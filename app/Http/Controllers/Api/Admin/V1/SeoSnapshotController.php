<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\GscCoverageState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Reports\Console\ReportRun;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Http\Concerns\BuildsSeoSnapshot;
use SsSystems\Platform\Seo\Http\Concerns\BuildsSeoToolBlocks;
use SsSystems\Platform\Seo\Http\Concerns\ServesStoredSeoSnapshot;
use SsSystems\Platform\Seo\SitemapStatus;
use SsSystems\Platform\Seo\Snapshot\Contracts\SeoSnapshotSource;

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
 *   - `automated_actions: false` — this app is a marketing site for a SaaS
 *     product with no page-title/meta-description content for the shared
 *     autopilot to rewrite, so it must never get it.
 *
 * 2026-11-01: `rankings`/`keyword_research`/`dataforseo`/`ai_traffic`/
 * `map_pack`/`intel` are now the kit's own SsSystems\Platform\Seo\Http\
 * Concerns\BuildsSeoToolBlocks (the same blocks J. Peterson Design's/
 * gs.construction's report pages carry — Patryk, 2026-10-31: "we should be
 * using the same ... in the kit"). This REPLACES this class's former
 * `rankingsSnapshot()` (the "Where you rank" panel fed from
 * App\Console\Commands\SeoRankCheck's saved SeoSyncRun result) — that
 * command and App\Services\DataForSeoService are deleted: the kit's
 * seo:track-rankings (seo_rank_snapshots table) is now the only source,
 * for both this screen's `rankings` key and the health score's rankings
 * measure (EloquentHealthDataReader::latestRankSnapshots()).
 *
 * 2026-09-27: `top_queries`/`top_pages`/`search_appearance`/`clarity` were
 * added — this site holds the exact same `gsc_query_metrics`/
 * `gsc_search_appearance_metrics` rows gs.construction does, and the SEO
 * screen's cards for them (Top Queries/Pages tables read a separate
 * paginated endpoint, but "How Your Results Look on Google" and "Website
 * Experience" read the snapshot directly) sat empty for no reason but a
 * missing method call — all four are now the kit's own
 * `BuildsSeoSnapshot::topTenRows()`/`searchAppearanceSnapshot()`/
 * `claritySnapshot()`, no site-only logic needed. `clarity` reads
 * `available: false` honestly until `seo:clarity-sync` (kit 0.20.0,
 * 2026-11-02 — see routes/console.php) has written at least one
 * `clarity_daily_metrics` row; `claritySnapshot()`'s own `clarityMetricsQuery()`
 * default reads that bare table name directly, no site-only override
 * needed here either. A DIFFERENT reader (the kit's
 * `Seo\Clarity\StoreClarityMetricsReader`) feeds the seo:clarity-health
 * REPORT, not this snapshot card.
 *
 * The four BuildsSeoSnapshot hooks below (gscCoverageStateModel/
 * seoReportControllerClass/dispatchChannelSync/formatDuration) exist so the
 * kit trait never names an App\* class — see its own docblock.
 */
class SeoSnapshotController extends Controller implements SeoSnapshotSource
{
    use BuildsApiResponses;
    use BuildsSeoToolBlocks;
    use BuildsSeoSnapshot {
        refreshSnapshot as baseRefreshSnapshot;
    }
    use ServesStoredSeoSnapshot;

    /** Mirrors ss-systems' SeoReports Livewire component's own TREND_DAYS. */
    public const TREND_DAYS = [7, 14, 30, 60, 90, 180, 360];

    /**
     * Public: App\Http\Controllers\Api\Admin\V1\SeoReportController's
     * regenerate() forgets this same key after a run, so a report's
     * markdown output and this screen's cached search snapshot never
     * disagree about how fresh they are.
     */
    public const SEARCH_SNAPSHOT_CACHE_PREFIX = 'admin.seo-reports.search-snapshot:';

    /**
     * The stored snapshot (kit 0.26.0): built every 15 minutes and after
     * each Search Console sync by seo:snapshot-store, `computed_at` saying
     * when — see the kit's ServesStoredSeoSnapshot. `?fresh=1` builds it now.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return $this->itemResponse($this->storedSnapshot($request));
    }

    /**
     * Every block of the snapshot, built from the tables now.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(Request $request): array
    {
        $trendDays = $this->normalizeTrendDays((int) $request->integer('trend_days', 14));
        $topDays = $this->normalizeTopDays((int) $request->integer('top_days', 28));
        $search = $this->searchSnapshot($trendDays);
        $rankDays = max(1, min(90, (int) $request->integer('rank_days', 7)));

        return [
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
            // The kit's SEO tools (0.19.1): rankings, keyword research,
            // competitor visibility, AI traffic, map-pack and intel, in
            // the shapes ss.systems' report page reads everywhere else
            // — see this class's own docblock for what this replaces.
            ...$this->seoToolBlocks($rankDays, fn (string $key, int $ttl, callable $build): array => Cache::remember($key, $ttl, $build)),
            'gsc_errors' => $this->gscErrorSnapshot(),
            'sitemaps' => SitemapStatus::snapshot((string) config('services.google.search_console_property')),
            // The AI-readability feeds (2026-11-10), in the shape
            // gs.construction's snapshot carries — the hub's "AI
            // readability feeds" list reads label/url/ok/age/stale.
            'geo' => $this->geoSnapshot(),
            // How our results LOOK on Google — this site holds the same
            // gsc_search_appearance_metrics rows gsc/jpeterson do (see
            // database/migrations), just never had this key wired.
            'search_appearance' => $this->searchAppearanceSnapshot(
                $this->normalizeTopDays((int) $request->integer('appearance_days', 28))
            ),
            // Honestly `available: false` until seo:clarity-sync has
            // written a clarity_daily_metrics row (see this class's own
            // docblock); the "Website Experience" card already reads
            // that as "no visitor-behavior data synced yet" rather than
            // an error.
            'clarity' => $this->claritySnapshot(
                $this->normalizeWindowDays((int) $request->integer('clarity_days', 7))
            ),
            // hive.contractors must NEVER get the shared autopilot: it
            // rewrites page titles/descriptions, and this app's marketing
            // copy is hand-written product messaging, not local-SEO
            // boilerplate a bot should touch.
            'automated_actions' => false,
        ];
    }

    /**
     * What the snapshot's blocks cache for themselves — health, search,
     * pulse, clarity, sitemaps and the SEO-tool blocks — so a build that
     * must be fresh reads the tables.
     */
    public function forgetSnapshotCaches(Request $request): void
    {
        $keys = [
            'admin.seo-reports.health-snapshot',
            $this->searchSnapshotCacheKey($this->normalizeTrendDays((int) $request->integer('trend_days', 14))),
            $this->claritySnapshotCacheKey($this->normalizeWindowDays((int) $request->integer('clarity_days', 7))),
            'admin.seo-reports.pulse',
            ...self::seoToolBlockCacheKeys(),
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        SitemapStatus::forget((string) config('services.google.search_console_property'));
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
     * POST seo/snapshot/refresh must also forget every cache key the kit's
     * SEO-tool blocks may have written (BuildsSeoToolBlocks::
     * seoToolBlockCacheKeys()) — the trait's own refreshSnapshot() (aliased
     * above as baseRefreshSnapshot()) only knows about this site's own
     * search/health caches.
     */
    public function refreshSnapshot(Request $request): JsonResponse
    {
        foreach (self::seoToolBlockCacheKeys() as $key) {
            Cache::forget($key);
        }

        return $this->baseRefreshSnapshot($request);
    }

    /**
     * The four AI feeds (/llms.txt, /llms-full.txt, /ai-feed.json,
     * /geo/answers.json), each as label/url/ok/age/stale — the shape
     * gs.construction's snapshot carries and the hub's "AI readability
     * feeds" list reads. On gs.construction the two llms files are written
     * to disk nightly, so `ok`/`age`/`stale` come from the file; every feed
     * here is a live route rendered on request (no file, no nightly job),
     * so `ok` is "the route is registered", `age` is null and `stale` is
     * never true — the hub shows each as "live". Rooted on the marketing
     * host, like every public URL this app hands out.
     *
     * `faq_bank` (kit 0.24.0, 2026-11-10) is the FAQ bank's own summary —
     * how many derived answers from how many pages /geo/answers.json and
     * /llms-full.txt carry after the hand-written list, and when
     * seo:faq-bank last wrote one — read through AppServiceProvider's
     * FaqBankStore binding. Guarded the way pulseSnapshot() guards
     * site_events: the migration ships with it, but a snapshot never 500s
     * over a table.
     *
     * @return array{feeds: list<array{label: string, url: string, ok: bool, age: ?string, stale: bool}>, faq_bank: array{answers: int, pages: int, last_generated_at: ?string}}
     */
    protected function geoSnapshot(): array
    {
        $feeds = [];

        foreach (['llms' => 'llms.txt', 'llms-full' => 'llms-full.txt', 'ai-feed' => 'ai-feed.json', 'geo.answers' => 'geo/answers.json'] as $route => $path) {
            $feeds[] = [
                'label' => $path,
                'url' => marketing_url($path),
                'ok' => Route::has($route),
                'age' => null,
                'stale' => false,
            ];
        }

        $bank = app(FaqBankStore::class);

        return [
            'feeds' => $feeds,
            'faq_bank' => $bank->exists() ? $bank->summary() : ['answers' => 0, 'pages' => 0, 'last_generated_at' => null],
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
        RunArtisanCommandDetached::dispatch($command);
    }

    protected function formatDuration(int $milliseconds): string
    {
        return ReportRun::formatSeconds($milliseconds);
    }
}
