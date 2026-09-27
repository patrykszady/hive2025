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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Seo\Http\Concerns\BuildsSeoSnapshot;
use SsSystems\Platform\Seo\SitemapStatus;

/**
 * GET seo/snapshot — the SEO screen's read. `pulse` is the Site Pulse block
 * (ss-systems/platform-kit's Pulse — see App\Providers\AppServiceProvider's
 * Recorder/SnapshotBuilder bindings and docs/PULSE.md in the kit); every
 * other block was ported from dawnsellshomes' identical controller once
 * this app grew the shared SEO tables/adapters (gsc_query_metrics,
 * gsc_daily_totals, gsc_coverage_states, bing_traffic_stats,
 * bing_daily_totals — see database/migrations), and as of 2026-09-27 lives
 * as ss-platform-kit's SsSystems\Platform\Seo\Http\Concerns\BuildsSeoSnapshot
 * (confirmed byte-identical against dawnsellshomes' own controller — see the
 * kit's docs/SEO-SNAPSHOT.md) — this class keeps only what's genuinely
 * this site's own:
 *
 *   - `pulse`/`pulseSnapshot()` — Site Pulse, not part of dawn's shape.
 *   - `rankings`/`rankingsSnapshot()` — the "Where you rank" panel, gated
 *     on DataForSEO; dawn has no equivalent block.
 *   - `automated_actions: false` — this app is a marketing site for a SaaS
 *     product with no page-title/meta-description content for the shared
 *     autopilot to rewrite, so it must never get it.
 *
 * 2026-09-27: `top_queries`/`top_pages`/`search_appearance`/`clarity` were
 * added — this site holds the exact same `gsc_query_metrics`/
 * `gsc_search_appearance_metrics` rows gs.construction does, and the SEO
 * screen's cards for them (Top Queries/Pages tables read a separate
 * paginated endpoint, but "How Your Results Look on Google" and "Website
 * Experience" read the snapshot directly) sat empty for no reason but a
 * missing method call — all four are now the kit's own
 * `BuildsSeoSnapshot::topTenRows()`/`searchAppearanceSnapshot()`/
 * `claritySnapshot()`, no site-only logic needed. `clarity` reads honestly
 * `available: false` (this site has never synced Microsoft Clarity into a
 * database table — see `App\Support\Seo\Reports\ClaritySettingsMetricsReader`'s
 * own docblock, which is a DIFFERENT reader for the seo:clarity-health
 * REPORT, not this snapshot card).
 *
 * The four BuildsSeoSnapshot hooks below (gscCoverageStateModel/
 * seoReportControllerClass/dispatchChannelSync/formatDuration) exist so the
 * kit trait never names an App\* class — see its own docblock.
 */
class SeoSnapshotController extends Controller
{
    use BuildsApiResponses;
    use BuildsSeoSnapshot;

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
        $topDays = $this->normalizeTopDays((int) $request->integer('top_days', 28));
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
                // Embedded top-ten (2026-09-27, parity with gsc's shape) —
                // the SEO screen's own Top Queries/Top Pages tables page
                // through GET seo/top-rows instead; this is for any other
                // consumer still reading the snapshot directly.
                'top_queries' => $this->topTenRows('query', $topDays),
                'top_pages' => $this->topTenRows('page', $topDays),
                'rankings' => $this->rankingsSnapshot(),
                'gsc_errors' => $this->gscErrorSnapshot(),
                'sitemaps' => SitemapStatus::snapshot((string) config('services.google.search_console_property')),
                // How our results LOOK on Google — this site holds the same
                // gsc_search_appearance_metrics rows gsc/jpeterson do (see
                // database/migrations), just never had this key wired.
                'search_appearance' => $this->searchAppearanceSnapshot(
                    $this->normalizeTopDays((int) $request->integer('appearance_days', 28))
                ),
                // Honestly `available: false` — this site has never synced
                // Microsoft Clarity into a database table (see
                // ClaritySettingsMetricsReader's own docblock); the "Website
                // Experience" card already reads that as "no visitor-behavior
                // data synced yet" rather than an error.
                'clarity' => $this->claritySnapshot(
                    $this->normalizeWindowDays((int) $request->integer('clarity_days', 7))
                ),
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

    protected function gscCoverageStateModel(): string
    {
        return GscCoverageState::class;
    }

    protected function seoReportControllerClass(): string
    {
        return SeoReportController::class;
    }

    protected function dispatchChannelSync(string $command): void
    {
        RunSeoChannelSyncJob::dispatch($command);
    }

    protected function formatDuration(int $milliseconds): string
    {
        return SeoReportRun::formatSeconds($milliseconds);
    }
}
