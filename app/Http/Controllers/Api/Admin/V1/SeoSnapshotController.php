<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Console\Commands\SeoRankCheck;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\RunSeoChannelSyncJob;
use App\Models\GscCoverageState;
use App\Models\SeoSyncRun;
use App\Support\Seo\DataForSeoSettings;
use App\Support\SeoReportRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;
use SsSystems\Platform\Seo\SitemapStatus;

/**
 * GET seo/snapshot — the SEO screen's read. `pulse` is the Site Pulse block
 * (ss-systems/platform-kit's Pulse — see App\Providers\AppServiceProvider's
 * Recorder/SnapshotBuilder bindings and docs/PULSE.md in the kit); every
 * other block was ported from dawnsellshomes' identical controller once
 * this app grew the shared SEO tables/adapters (gsc_query_metrics,
 * gsc_daily_totals, gsc_coverage_states, bing_traffic_stats,
 * bing_daily_totals — see database/migrations):
 *
 *   - `health`/`report_stats` (Phase 5 shape) — health delegates to
 *     seo:health --json; report_stats delegates to SeoReportController so
 *     the report list's availability/freshness rules have exactly one
 *     implementation.
 *   - `search`/`trend`/`trend_through` — Search Console + Bing totals,
 *     current 7 days vs. the 7 before, plus the day-by-day trend series.
 *   - `gsc_errors` — the "Pages Google Is Having Trouble With" card's
 *     embedded summary.
 *   - `sitemaps` — SsSystems\Platform\Seo\SitemapStatus, bound through
 *     GoogleSearchConsoleService, so this reads 'unconfigured' rather than
 *     erroring while GSC_CREDENTIALS/GSC_PROPERTY are unset.
 *   - `automated_actions: false` — this app is a marketing site for a SaaS
 *     product with no page-title/meta-description content for the shared
 *     autopilot to rewrite, so it must never get it.
 *
 * coverageTotalsAsOf() always returns null: like the other kit sites, this
 * app has no gsc_coverage_state_history table, so the gsc_errors card's
 * week-over-week chevron has nothing to compare against.
 */
class SeoSnapshotController extends Controller
{
    use BuildsApiResponses;

    /** Mirrors ss-systems' SeoReports Livewire component's own TREND_DAYS. */
    public const TREND_DAYS = [7, 14, 30, 60, 90, 180, 360];

    /**
     * Public: App\Http\Controllers\Api\Admin\V1\SeoReportController's
     * regenerate() forgets this same key after a run, so a report's
     * markdown output and this screen's cached search snapshot never
     * disagree about how fresh they are.
     */
    public const SEARCH_SNAPSHOT_CACHE_PREFIX = 'admin.seo-reports.search-snapshot:';

    public function __invoke(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));
        $search = $this->searchSnapshot($trendDays);

        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'pulse' => $this->pulseSnapshot(),
                'health' => $this->healthSnapshot(),
                'report_stats' => $this->reportStats(),
                'search' => $search,
                'trend' => $this->trendChartData($search),
                'trend_through' => $search['through'] ?? null,
                'rankings' => $this->rankingsSnapshot(),
                'gsc_errors' => $this->gscErrorSnapshot(),
                'sitemaps' => SitemapStatus::snapshot((string) config('services.google.search_console_property')),
                // hive.contractors must NEVER get the shared autopilot: it
                // rewrites page titles/descriptions, and this app's marketing
                // copy is hand-written product messaging, not local-SEO
                // boilerplate a bot should touch.
                'automated_actions' => false,
            ],
        ]);
    }

    /**
     * Cached 5 minutes (shorter than every other section, which run
     * 15-30 minutes elsewhere): Pulse is meant to read as close to live.
     * Schema::hasTable guards belt-and-suspenders even though the migration
     * this app ships means the table always exists in practice. No
     * `search_event` is configured for this site (see AppServiceProvider's
     * SnapshotBuilder binding) — the marketing site has no search feature —
     * so `searches`/`searched_cities`/`filters` are correctly absent from
     * this shape, not sent as zero.
     */
    protected function pulseSnapshot(): array
    {
        if (! Schema::hasTable('site_events')) {
            return [
                'timezone' => 'America/Chicago',
                'window_days' => 7,
                'trend_days' => 14,
                'mobile_share_pct' => 0,
                'totals' => ['visitors' => 0, 'page_views' => 0],
                'totals_prev' => ['visitors' => 0, 'page_views' => 0],
                'days' => [],
                'features' => [],
                'hours' => [],
                'visitor_cities' => [],
                'top_pages' => [],
                'js_errors' => [],
            ];
        }

        return Cache::remember('admin.seo-reports.pulse', now()->addMinutes(5),
            fn () => app(SnapshotBuilder::class)->build());
    }

    /**
     * POST seo/snapshot/refresh — queues fresh Search Console + Bing pulls,
     * detached, via the same App\Jobs\RunSeoChannelSyncJob the schedule
     * uses. Both ends are logged to the 'seo-reports' channel: this
     * endpoint starting/finishing (queuing the two syncs), not the syncs'
     * own completion, which each command logs to its own
     * storage/logs/seo-*.log file on its own schedule.
     */
    public function refreshSnapshot(Request $request): JsonResponse
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));
        $startedAt = Carbon::now();
        $start = microtime(true);

        Log::channel('seo-reports')->info('snapshot refresh started', [
            'trend_days' => $trendDays,
            'requested_by' => $request->header('X-Admin-User'),
            'screen' => $request->header('X-Admin-Screen'),
        ]);

        $cachesCleared = [
            'admin.seo-reports.health-snapshot',
            self::SEARCH_SNAPSHOT_CACHE_PREFIX.$trendDays,
        ];

        try {
            Cache::forget('admin.seo-reports.health-snapshot');
            Cache::forget(self::SEARCH_SNAPSHOT_CACHE_PREFIX.$trendDays);

            RunSeoChannelSyncJob::dispatch('seo:gsc-sync');
            RunSeoChannelSyncJob::dispatch('seo:bing-sync');

            $response = $this->__invoke($request);
            $data = $response->getData(true)['data'];

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::channel('seo-reports')->info('snapshot refresh finished', [
                'duration_ms' => $durationMs,
                'caches_cleared' => $cachesCleared,
                'queued' => ['seo:gsc-sync', 'seo:bing-sync'],
                'trend_points' => count($data['trend'] ?? []),
                'health_score_present' => isset($data['health']['score']) && $data['health']['score'] !== null,
            ]);

            $data['ok'] = true;
            $data['message'] = sprintf('Dashboard metrics refreshed in %s. A fresh Search Console + Bing pull was queued.', SeoReportRun::formatSeconds($durationMs));
            $data['run'] = [
                'duration_ms' => $durationMs,
                'caches_cleared' => $cachesCleared,
                'queued' => ['seo:gsc-sync', 'seo:bing-sync'],
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => Carbon::now()->toIso8601String(),
                'log_channel' => 'seo-reports',
            ];

            return $this->itemResponse($data);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::channel('seo-reports')->error('snapshot refresh finished', [
                'duration_ms' => $durationMs,
                'caches_cleared' => $cachesCleared,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            return $this->itemResponse([
                'ok' => false,
                'message' => 'Could not refresh dashboard metrics: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * seo:health isn't gated by App\Support\Seo\Reports\ReportCapabilities
     * in the same way a report needing area_catalog would be — 'health'
     * only needs health_data + query_metrics, both of which this site
     * provides — so this actually runs for real. --json bypasses the
     * markdown/table branch entirely and always exits 0 — see
     * App\Console\Commands\SeoHealth.
     *
     * prior_score/prior_as_of read HealthReport's own health-history.json
     * ledger (App\Support\Seo\Reports\EloquentHealthDataReader) for the
     * entry from ~7 days before today's freshly-generated score — the
     * ledger gets one new dated entry per run, and seo:health is scheduled
     * WEEKLY (routes/console.php), so in steady state that is simply the
     * previous run.
     */
    protected function healthSnapshot(): array
    {
        return Cache::remember('admin.seo-reports.health-snapshot', now()->addMinutes(15), function (): array {
            try {
                Artisan::call('seo:health --json');
                $raw = trim(Artisan::output());
                $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

                $pillars = collect($data['pillars'] ?? [])
                    ->map(function (array $pillar): array {
                        $raw = $pillar['score'] ?? null;
                        $score = $raw === null ? null : (int) $raw;

                        return [
                            'name' => (string) ($pillar['name'] ?? 'Unknown'),
                            'score' => $score,
                            'color' => $score === null ? 'zinc' : ($score >= 80 ? 'emerald' : ($score >= 60 ? 'amber' : 'rose')),
                        ];
                    })
                    ->values()
                    ->all();

                [$priorScore, $priorAsOf] = $this->priorHealthScore();

                return [
                    'score' => isset($data['score']) ? (int) $data['score'] : null,
                    'prior_score' => $priorScore,
                    'prior_as_of' => $priorAsOf,
                    'pillars' => $pillars,
                ];
            } catch (\Throwable) {
                return ['score' => null, 'prior_score' => null, 'prior_as_of' => null, 'pillars' => []];
            }
        });
    }

    /**
     * @return array{0: ?int, 1: ?string} [prior_score, prior_as_of ('Y-m-d')]
     */
    protected function priorHealthScore(): array
    {
        $ledger = app(HealthDataReader::class)->healthLedger();
        if ($ledger === []) {
            return [null, null];
        }

        $today = now()->toDateString();
        $earlier = collect($ledger)->filter(fn ($score, string $date) => $date < $today)->sortKeys();

        if ($earlier->isEmpty()) {
            return [null, null];
        }

        $targetDate = Carbon::parse($today)->subDays(7)->toDateString();
        $onOrBeforeTarget = $earlier->filter(fn ($score, string $date) => $date <= $targetDate);

        $priorDate = $onOrBeforeTarget->isNotEmpty()
            ? (string) $onOrBeforeTarget->keys()->last()
            : (string) $earlier->keys()->first();

        return [(int) $earlier[$priorDate], $priorDate];
    }

    /** Delegates to SeoReportController so the report list's own availability/freshness rules have exactly one implementation. */
    protected function reportStats(): array
    {
        $controller = app(SeoReportController::class);

        return $controller->reportStats($controller->files());
    }

    /**
     * Every query (or page) in the window, a page at a time — the same
     * shape ss-systems' SeoReports screen's two search tables read from.
     */
    public function topRows(Request $request): JsonResponse
    {
        $dimension = $request->string('dimension')->toString() === 'page' ? 'page' : 'query';
        $days = $this->normalizeTopDays((int) $request->integer('days', 28));
        $sort = $request->string('sort', 'clicks')->toString();
        $direction = $request->string('dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        if (! Schema::hasTable('gsc_query_metrics')) {
            return $this->paginatedResponse(new LengthAwarePaginator([], 0, $this->perPage($request)), fn ($r) => $r);
        }

        $paginator = $this->topRowsQuery($dimension, $sort, $direction, $days)->paginate($this->perPage($request));
        $prior = $this->priorTopRows($dimension, $days, collect($paginator->items())->pluck('dim')->all());

        return $this->paginatedResponse($paginator, fn ($r) => $this->shapeTopRow($dimension, $r, $prior));
    }

    protected function normalizeTrendDays(int $days): int
    {
        return in_array($days, self::TREND_DAYS, true) ? $days : 14;
    }

    protected function normalizeTopDays(int $days): int
    {
        return in_array($days, [7, 28, 90], true) ? $days : 28;
    }

    /**
     * Per-channel totals (Google Search Console, Bing) over the last 7
     * days vs. the 7 before that, plus the day-by-day trend series and the
     * coverage tile totals. Cached 15 minutes.
     */
    protected function searchSnapshot(int $trendDays): array
    {
        return Cache::remember($this->searchSnapshotCacheKey($trendDays), now()->addMinutes(15), function () use ($trendDays): array {
            $today = Carbon::today();
            $currStart = $today->copy()->subDays(6);
            $prevStart = $today->copy()->subDays(13);
            $prevEnd = $today->copy()->subDays(7);

            $channels = [
                'gsc' => ['label' => 'Google Search Console', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'delta_clicks' => 0.0],
                'bing' => ['label' => 'Bing Webmaster', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0, 'delta_clicks' => 0.0],
            ];

            if (Schema::hasTable('gsc_query_metrics')) {
                $hasDailyTotals = Schema::hasTable('gsc_daily_totals')
                    && DB::table('gsc_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();
                $totalsTable = $hasDailyTotals ? 'gsc_daily_totals' : 'gsc_query_metrics';

                $curr = DB::table($totalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                    ->first();
                $prev = DB::table($totalsTable)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks')
                    ->first();

                $currClicks = (int) ($curr->clicks ?? 0);
                $currImpressions = (int) ($curr->impressions ?? 0);
                $channels['gsc'] = [
                    'label' => 'Google Search Console',
                    'clicks' => $currClicks,
                    'impressions' => $currImpressions,
                    'ctr' => $currImpressions > 0 ? round(($currClicks / $currImpressions) * 100, 2) : 0.0,
                    'position' => round((float) ($curr->position ?? 0), 2),
                    'delta_clicks' => $this->percentDelta($currClicks, (int) ($prev->clicks ?? 0)),
                ];
            }

            if (Schema::hasTable('bing_traffic_stats')) {
                $positionRow = DB::table('bing_traffic_stats')
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('AVG(position) as position')
                    ->first();

                $hasBingDailyTotals = Schema::hasTable('bing_daily_totals')
                    && DB::table('bing_daily_totals')->whereBetween('date', [$prevStart->toDateString(), $today->toDateString()])->exists();
                $bingTotalsTable = $hasBingDailyTotals ? 'bing_daily_totals' : 'bing_traffic_stats';

                $curr = DB::table($bingTotalsTable)
                    ->whereBetween('date', [$currStart->toDateString(), $today->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->first();
                $prev = DB::table($bingTotalsTable)
                    ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
                    ->selectRaw('SUM(clicks) as clicks')
                    ->first();

                $currClicks = (int) ($curr->clicks ?? 0);
                $currImpressions = (int) ($curr->impressions ?? 0);
                $channels['bing'] = [
                    'label' => 'Bing Webmaster',
                    'clicks' => $currClicks,
                    'impressions' => $currImpressions,
                    'ctr' => $currImpressions > 0 ? round(($currClicks / $currImpressions) * 100, 2) : 0.0,
                    'position' => round((float) ($positionRow->position ?? 0), 2),
                    'delta_clicks' => $this->percentDelta($currClicks, (int) ($prev->clicks ?? 0)),
                ];
            }

            ['rows' => $dailyClicks, 'through' => $through] = $this->dailySeries($trendDays);

            $coverage = ['total' => 0, 'problem' => 0, 'forbidden' => 0, 'not_indexed' => 0, 'duplicate' => 0];
            if (Schema::hasTable('gsc_coverage_states')) {
                $coverage['total'] = (int) DB::table('gsc_coverage_states')->count();
                $coverage['problem'] = (int) DB::table('gsc_coverage_states')
                    ->where(fn ($q) => $q->where('verdict', '!=', 'PASS')->orWhereNull('verdict'))
                    ->count();
                $coverage['forbidden'] = (int) DB::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->count();
                $coverage['not_indexed'] = (int) DB::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->count();
                $coverage['duplicate'] = (int) DB::table('gsc_coverage_states')
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->count();
            }

            return [
                'channels' => $channels,
                'daily_clicks' => $dailyClicks,
                'through' => $through,
                'coverage' => $coverage,
            ];
        });
    }

    /**
     * The chart rows: one per day with every metric for every channel,
     * flat keys (`gsc_clicks`, `bing_ctr`, `combined_impressions`...).
     */
    protected function trendChartData(array $snapshot): array
    {
        return array_values($snapshot['daily_clicks'] ?? []);
    }

    /**
     * Day-by-day clicks, impressions, CTR and position per channel over
     * the last `$trendDays` days — ending on the last day that has data,
     * not today (Search Console publishes two to three days behind).
     *
     * @return array{rows: list<array<string, mixed>>, through: ?string}
     */
    protected function dailySeries(int $trendDays): array
    {
        $gsc = $this->channelDaily('gsc_daily_totals', 'gsc_query_metrics');
        $bing = $this->channelDaily('bing_daily_totals', 'bing_traffic_stats');

        $through = max((string) $gsc['max'], (string) $bing['max']);
        if ($through === '') {
            return ['rows' => [], 'through' => null];
        }

        $end = Carbon::parse($through);
        $start = (clone $end)->subDays($trendDays - 1);
        $gscRows = $this->channelRows('gsc_daily_totals', 'gsc_query_metrics', $start, $end, $gsc['source']);
        $bingRows = $this->channelRows('bing_daily_totals', 'bing_traffic_stats', $start, $end, $bing['source']);
        $bingPositions = $this->bingDailyPositions($start, $end);

        $rows = [];
        for ($d = clone $start; $d->lte($end); $d->addDay()) {
            $day = $d->toDateString();
            $g = $this->channelDay($gscRows[$day] ?? null, $day, $gsc);
            $b = $this->channelDay($bingRows[$day] ?? null, $day, $bing);
            if ($b['position'] === null && isset($bingPositions[$day]) && $b['clicks'] !== null) {
                $b['position'] = $bingPositions[$day];
            }

            $complete = ! ($gsc['max'] !== null && $g['clicks'] === null)
                && ! ($bing['max'] !== null && $b['clicks'] === null);

            $combinedClicks = $complete && ! ($g['clicks'] === null && $b['clicks'] === null)
                ? (int) $g['clicks'] + (int) $b['clicks'] : null;
            $combinedImpressions = $complete && ! ($g['impressions'] === null && $b['impressions'] === null)
                ? (int) $g['impressions'] + (int) $b['impressions'] : null;

            $rows[] = [
                'date' => $d->format('M j'),
                'day' => $day,
                'gsc_clicks' => $g['clicks'],
                'gsc_impressions' => $g['impressions'],
                'gsc_ctr' => $g['ctr'],
                'gsc_position' => $g['position'],
                'bing_clicks' => $b['clicks'],
                'bing_impressions' => $b['impressions'],
                'bing_ctr' => $b['ctr'],
                'bing_position' => $b['position'],
                'combined_clicks' => $combinedClicks,
                'combined_impressions' => $combinedImpressions,
                'combined_ctr' => $combinedImpressions === null ? null : ($combinedImpressions > 0 ? round($combinedClicks / $combinedImpressions * 100, 2) : 0.0),
                'combined_position' => null,
            ];
        }

        $hasData = fn (array $r): bool => $r['gsc_clicks'] !== null || $r['bing_clicks'] !== null;
        while ($rows !== [] && ! $hasData($rows[0])) {
            array_shift($rows);
        }
        while ($rows !== [] && ! $hasData($rows[array_key_last($rows)])) {
            array_pop($rows);
        }

        return ['rows' => array_values($rows), 'through' => $end->format('M j')];
    }

    /** @return array{source: ?string, min: ?string, max: ?string} */
    protected function channelDaily(string $totalsTable, string $fallbackTable): array
    {
        foreach ([$totalsTable, $fallbackTable] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $range = DB::table($table)->selectRaw('MIN(DATE(date)) as min_day, MAX(DATE(date)) as max_day')->first();
            if ($range && $range->max_day) {
                return ['source' => $table, 'min' => (string) $range->min_day, 'max' => (string) $range->max_day];
            }
        }

        return ['source' => null, 'min' => null, 'max' => null];
    }

    /** @return array<string, object> keyed by Y-m-d */
    protected function channelRows(string $totalsTable, string $fallbackTable, Carbon $start, Carbon $end, ?string $source): array
    {
        if ($source === null) {
            return [];
        }

        $query = DB::table($source)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());

        $rows = $source === $totalsTable
            ? $query->selectRaw('DATE(date) as day, SUM(clicks) as clicks, SUM(impressions) as impressions, '
                .(Schema::hasColumn($source, 'position') ? 'AVG(NULLIF(position, 0))' : 'NULL').' as position')
                ->groupBy('day')->get()
            : $query->selectRaw('DATE(date) as day, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(NULLIF(position, 0)) as position')
                ->groupBy('day')->get();

        return $rows->keyBy(fn ($r) => (string) $r->day)->all();
    }

    /** @return array<string, float> */
    protected function bingDailyPositions(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('bing_traffic_stats')) {
            return [];
        }

        return DB::table('bing_traffic_stats')
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('DATE(date) as day, AVG(NULLIF(position, 0)) as position')
            ->groupBy('day')
            ->get()
            ->filter(fn ($r) => $r->position !== null)
            ->mapWithKeys(fn ($r) => [(string) $r->day => round((float) $r->position, 2)])
            ->all();
    }

    /**
     * @param  array{min: ?string, max: ?string}  $range
     * @return array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float}
     */
    protected function channelDay(?object $row, string $day, array $range): array
    {
        $collected = $range['min'] !== null && $day >= $range['min'] && $day <= $range['max'];

        if (! $row && ! $collected) {
            return ['clicks' => null, 'impressions' => null, 'ctr' => null, 'position' => null];
        }

        $clicks = (int) ($row->clicks ?? 0);
        $impressions = (int) ($row->impressions ?? 0);
        $position = isset($row->position) && (float) $row->position > 0 ? round((float) $row->position, 2) : null;

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'position' => $position,
        ];
    }

    protected function topRowsQuery(string $dimension, string $sort, string $direction, int $topDays): Builder
    {
        $sortableColumns = ['clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($sort, [$dimension, ...$sortableColumns], true)) {
            $sort = 'clicks';
        }

        $orderExpressions = [
            'clicks' => 'SUM(clicks)',
            'impressions' => 'SUM(impressions)',
            'position' => 'AVG(position)',
            'ctr' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END',
            $dimension => $dimension,
        ];

        $orderBy = $orderExpressions[$sort] ?? 'SUM(clicks)';
        $days = max(1, $topDays);

        return DB::table('gsc_query_metrics')
            ->whereBetween('date', [Carbon::today()->subDays($days - 1)->toDateString(), Carbon::today()->toDateString()])
            ->groupBy($dimension)
            ->selectRaw("{$dimension} as dim, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position")
            ->orderByRaw("{$orderBy} {$direction}");
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<string, object>
     */
    protected function priorTopRows(string $dimension, int $topDays, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        $days = max(1, $topDays);

        return DB::table('gsc_query_metrics')
            ->whereBetween('date', [
                Carbon::today()->subDays(2 * $days - 1)->toDateString(),
                Carbon::today()->subDays($days)->toDateString(),
            ])
            ->whereIn($dimension, $keys)
            ->groupBy($dimension)
            ->selectRaw("{$dimension} as dim, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position")
            ->get()
            ->keyBy(fn ($r) => (string) $r->dim);
    }

    /** @param  Collection<string, object>  $prior */
    protected function shapeTopRow(string $dimension, object $r, Collection $prior): array
    {
        $shape = fn ($row) => [
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'ctr' => (int) $row->impressions > 0 ? round(((int) $row->clicks / (int) $row->impressions) * 100, 2) : 0.0,
            'position' => round((float) $row->position, 2),
        ];

        return [$dimension => (string) $r->dim] + $shape($r) + [
            'prior' => ($p = $prior->get((string) $r->dim)) ? $shape($p) : null,
        ];
    }

    /**
     * The SEO screen's "Where you rank" panel reads this top-level
     * `rankings` key (ss-systems' seo-reports.blade.php: `$rk['live_serp']`
     * for the split, per-engine shape). Absent entirely — `[]` — until
     * DataForSEO is configured (App\Support\Seo\DataForSeoSettings), which
     * ss-systems already renders as "No data for this check yet." rather
     * than an error; unavailable must read calmly, never crash. A failed
     * check reads exactly the same as "not configured" here, never a
     * fabricated zero. The check itself runs on a schedule
     * (App\Console\Commands\SeoRankCheck, daily) and this reads its saved
     * result — never DataForSEO live inside the request: three live queries
     * took 21.6s, past the admin's 15s wait, so the whole SEO screen read
     * "Couldn't reach" every half hour (2026-09-27).
     */
    protected function rankingsSnapshot(): array
    {
        if (! app(DataForSeoSettings::class)->isConfigured()) {
            return [];
        }

        $saved = SeoSyncRun::summary(SeoRankCheck::SYNC_KEY);

        if (! is_array($saved['current'] ?? null)) {
            return [];
        }

        return [
            'live_serp' => ['current' => $saved['current']],
            'as_of' => isset($saved['checked_at']) ? Carbon::parse($saved['checked_at'])->toDateString() : null,
        ];
    }

    /** The "Pages Google Is Having Trouble With" card's embedded summary. */
    protected function gscErrorSnapshot(): array
    {
        if (! Schema::hasTable('gsc_coverage_states')) {
            return [
                'available' => false,
                'totals' => ['tracked' => 0, 'problem' => 0, 'pass' => 0, 'not_indexed' => 0],
                'totals_prev' => null,
                'buckets' => [],
                'latest_inspected' => null,
                'rows' => [],
            ];
        }

        $tracked = (int) GscCoverageState::query()->count();

        $problemQuery = GscCoverageState::query()->where(function ($q) {
            $q->where('verdict', '!=', 'PASS')
                ->orWhereNull('verdict')
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);
        });

        $problem = (int) (clone $problemQuery)->count();

        $bucketSpecs = [
            'Blocked (robots/forbidden)' => ['%forbidden%', '%blocked by robots.txt%'],
            'Not indexed' => ['%not indexed%'],
            'Duplicate/canonical' => ['%duplicate%', '%canonical%'],
            'Soft 404' => ['%soft 404%'],
            'Crawl/fetch errors' => ['%not found%', '%server error%', '%redirect error%'],
        ];

        $buckets = [];
        foreach ($bucketSpecs as $label => $patterns) {
            $count = (int) GscCoverageState::query()
                ->where(function ($q) use ($patterns) {
                    foreach ($patterns as $pattern) {
                        $q->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', [$pattern])
                            ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', [$pattern]);
                    }
                })
                ->count();
            $buckets[] = ['label' => $label, 'count' => $count];
        }

        $rows = (clone $problemQuery)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(25)
            ->get(['url', 'verdict', 'coverage_state', 'page_fetch_state', 'last_crawl_time', 'inspected_at', 'last_changed_at', 'consecutive_failures'])
            ->map(function (GscCoverageState $row): array {
                $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';

                return [
                    'url' => (string) $row->url,
                    'path' => (string) $path,
                    'issue' => $this->classifyGscIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
                    'verdict' => (string) ($row->verdict ?? 'UNKNOWN'),
                    'coverage_state' => (string) ($row->coverage_state ?? ''),
                    'page_fetch_state' => (string) ($row->page_fetch_state ?? ''),
                    'last_crawl_time' => $row->last_crawl_time?->toDateString(),
                    'inspected_at' => $row->inspected_at?->diffForHumans(),
                    'last_changed_at' => $row->last_changed_at?->diffForHumans(),
                    'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
                ];
            })
            ->all();

        $latestInspected = GscCoverageState::query()->max('inspected_at');
        $notIndexed = (int) GscCoverageState::query()->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])->count();

        return [
            'available' => true,
            'totals' => ['tracked' => $tracked, 'problem' => $problem, 'pass' => max(0, $tracked - $problem), 'not_indexed' => $notIndexed],
            'totals_prev' => $this->coverageTotalsAsOf(now()->subDays(7)),
            'buckets' => $buckets,
            'latest_inspected' => $latestInspected ? Carbon::parse((string) $latestInspected)->diffForHumans() : null,
            'rows' => $rows,
        ];
    }

    /**
     * Always null: this app has no gsc_coverage_state_history table, so
     * the tiles' week-over-week chevron has nothing to compare against.
     */
    protected function coverageTotalsAsOf(Carbon $cutoff): ?array
    {
        return null;
    }

    protected function classifyGscIssue(string $coverageState, string $pageFetchState, string $verdict): string
    {
        $text = strtolower(trim($coverageState.' '.$pageFetchState));

        if ($text === '' && strtoupper($verdict) === 'PASS') {
            return 'Indexed';
        }
        if (str_contains($text, 'forbidden') || str_contains($text, 'robots')) {
            return 'Blocked';
        }
        if (str_contains($text, 'not indexed')) {
            return 'Not indexed';
        }
        if (str_contains($text, 'duplicate') || str_contains($text, 'canonical')) {
            return 'Duplicate/canonical';
        }
        if (str_contains($text, 'soft 404')) {
            return 'Soft 404';
        }
        if (str_contains($text, 'server') || str_contains($text, 'not found') || str_contains($text, 'redirect')) {
            return 'Fetch error';
        }

        return strtoupper($verdict) === 'PASS' ? 'Indexed' : 'Other';
    }

    protected function searchSnapshotCacheKey(int $trendDays): string
    {
        return 'admin.seo-reports.search-snapshot:'.$trendDays;
    }

    protected function percentDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
