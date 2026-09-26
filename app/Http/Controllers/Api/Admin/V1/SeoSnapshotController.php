<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Pulse\SnapshotBuilder;

/**
 * GET seo/snapshot — the SEO screen's read. Still a stub for most sections:
 * this app has none of the other shared ss-systems SEO machinery's tables
 * (no gsc_daily_totals, no health/search/trend/gsc_errors/sitemaps), so
 * those are all simply omitted rather than sent as always-empty
 * placeholders — ss-systems' seo-reports view defaults each one with
 * `?? …` (see ss-systems/CLAUDE.md: "a key one site's API omits renders as
 * an empty card for that tenant alone"). `pulse` is the first real section:
 * ss-systems/platform-kit 0.10.0's Pulse (see App\Providers\
 * AppServiceProvider's Recorder/SnapshotBuilder bindings and
 * docs/PULSE.md in the kit).
 *
 * `automated_actions: false` opts Hive out of the shared autopilot's
 * rewrite-titles-and-descriptions panel — there is no page-title content
 * here for it to rewrite in the first place.
 */
class SeoSnapshotController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'automated_actions' => false,
                'pulse' => $this->pulseSnapshot(),
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
}
