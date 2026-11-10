<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource;

/**
 * The URLs seo:bing-coverage asks Bing about (kit 0.22.0, 2026-11-10) — the
 * exact set App\Http\Controllers\Api\Admin\V1\GscErrorController::
 * sitemapUrlSet() tracks for Search Console coverage, so the hub's Bing
 * and GSC cards count the same pages. Both read App\Support\Seo\
 * Inspection\MarketingSitemapSource (already bound to SitemapSource in
 * AppServiceProvider) — the same route/config inventory GET /sitemap.xml
 * itself renders from — rather than fetching sitemap.xml over HTTP or
 * duplicating sitemapUrlSet()'s flatten-by-locale loop.
 */
class BingCoverageUrls implements \SsSystems\Platform\Seo\Bing\Contracts\BingCoverageUrls
{
    public function __construct(private readonly SitemapSource $sitemap) {}

    public function urls(): array
    {
        return $this->sitemap->urls();
    }
}
