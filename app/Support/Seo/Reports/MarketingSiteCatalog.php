<?php

namespace App\Support\Seo\Reports;

use App\Support\MarketingSitemap;
use SsSystems\Platform\Reports\Contracts\SiteCatalog;

/**
 * SiteCatalog over this app's own App\Support\MarketingSitemap — NOT a live
 * self-fetch of {baseUrl}/sitemap.xml the way jpeterson-design's
 * AppSiteCatalog does. GET /sitemap.xml here is rendered on the fly by
 * App\Http\Controllers\SitemapController from the exact same route/config
 * inventory (see App\Support\Seo\Inspection\MarketingSitemapSource, which
 * reads it for the URL Inspection sweep); reading that in-process list here
 * is both simpler and current. SeoGbpParity, SeoSchemaAudit, SeoHealthCheck
 * and SeoInternalLinkSuggest each re-apply their OWN extension/limit filter
 * on top of this unfiltered list, same as every other site using the kit.
 */
class MarketingSiteCatalog implements SiteCatalog
{
    public function __construct(private readonly MarketingSitemap $sitemap) {}

    public function baseUrl(): string
    {
        return rtrim((string) config('app.marketing_url'), '/');
    }

    public function canonicalHost(): string
    {
        return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: '');
    }

    public function sitemapUrls(): array
    {
        $urls = [];
        foreach ($this->sitemap->entries() as $entry) {
            foreach ($entry['locales'] as $href) {
                $urls[] = trim($href);
            }
        }

        return array_values(array_unique($urls));
    }
}
