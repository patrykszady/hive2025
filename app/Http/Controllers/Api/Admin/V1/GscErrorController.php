<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\GscCoverageState;
use App\Support\MarketingSitemap;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\Http\Concerns\ServesGscErrors;

/**
 * The dedicated GSC Errors screen's endpoints — index (filtered, paginated
 * coverage rows + stats), refresh (queue a full sweep), prune-retired and
 * export. Ported from dawnsellshomes' identical controller.
 *
 * refresh() dispatches the kit's RunArtisanCommandDetached (this app's own
 * RunGscInspectBulkJob, a thin wrapper that only ever picked this one
 * command name, is gone — kit 0.12.0); its underlying 'seo:gsc-inspect-bulk'
 * command does real work once GSC_CREDENTIALS + GSC_PROPERTY are set and
 * the service account has permission on the property.
 *
 * index()/refresh()/pruneRetired()/export()/stats() plus their private
 * helpers now come from the kit's ServesGscErrors trait (kit 0.13.0,
 * CONSOLIDATION-PLAN.md's Kit 0.14.0 bullet). **Behavior change (Patryk,
 * 2026-09-27, mandatory across the estate):** pruneRetired() now only ever
 * deletes rows whose `source` column is `'sitemap'` — this app's own
 * pruneRetired() previously deleted ANY row absent from the sitemap
 * regardless of source, which is a real, live data-loss bug (this screen's
 * "Prune retired" button was deleting Search Console Page-indexing-export
 * or Googlebot-tracked-404 rows the moment such rows exist, since those
 * are correctly never in the marketing sitemap). stats() also grows the
 * three keys (`retired`/`sitemap_urls`/`inspection_coverage_pct`) gsc's/
 * jpeterson's identical screen already sends — additive, scoped the same
 * `source='sitemap'` way as the fix above.
 */
class GscErrorController extends Controller
{
    use BuildsApiResponses;
    use ServesGscErrors;

    protected function coverageStateModel(): string
    {
        return GscCoverageState::class;
    }

    protected function dispatchGscInspectBulkSweep(): void
    {
        RunArtisanCommandDetached::dispatch('seo:gsc-inspect-bulk', ['--limit' => 0, '--markdown' => true]);
    }

    /** This app's own existing wording (unlike gsc's/jpeterson's default "sitemap.xml" phrasing). */
    protected function sitemapUnreadableMessage(): string
    {
        return 'Prune skipped: could not read the site\'s URL inventory.';
    }

    /**
     * The set of URLs pruneRetired() treats as "still current" — keyed by
     * URL for O(1) lookup, flattened across every locale (the same
     * inventory App\Support\Seo\Inspection\MarketingSitemapSource sweeps).
     *
     * @return array<string, true>
     */
    protected function sitemapUrlSet(): array
    {
        $urls = [];
        foreach (app(MarketingSitemap::class)->entries() as $entry) {
            foreach ($entry['locales'] as $href) {
                $urls[$href] = true;
            }
        }

        return $urls;
    }
}
