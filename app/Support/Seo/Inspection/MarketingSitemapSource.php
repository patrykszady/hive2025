<?php

namespace App\Support\Seo\Inspection;

use App\Support\MarketingSitemap;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource;

/**
 * SitemapSource over this app's own App\Support\MarketingSitemap — the
 * exact same route/config-driven inventory GET /sitemap.xml itself is
 * rendered from (App\Http\Controllers\SitemapController), NOT a static
 * public/sitemap.xml file the way jpeterson-design/gsc read one. Reading
 * the same in-process entries the sitemap itself is built from is both
 * simpler and more current than fetching and parsing the rendered XML
 * would be, and every URL MarketingSitemap produces is already rooted on
 * config('app.marketing_url') regardless of what APP_URL is in this
 * environment — the same reasoning dawnsellshomes' AppSitemapSource (over
 * its own App\Support\SiteUrls) documents.
 *
 * MarketingSitemap::entries() carries one entry per ROUTE, each with one
 * URL per supported locale (en/pl/es) — flattening every locale's href is
 * what makes the sweep's pool match "every public marketing URL", not just
 * the English ones.
 *
 * The --sitemap= override (rare — a Console export or a one-off manual
 * run) still reads a literal XML file from disk, same as the other kit
 * sites, for anyone who really does want to hand the sweep a specific file.
 */
class MarketingSitemapSource implements SitemapSource
{
    public function __construct(private readonly MarketingSitemap $sitemap) {}

    /**
     * @param  ?string  $override  A path to an XML file to read instead of
     *                             this site's own route inventory. Null uses MarketingSitemap::entries().
     * @return list<string>
     */
    public function urls(?string $override = null): array
    {
        if ($override !== null && $override !== '') {
            return $this->urlsFromFile($override);
        }

        $urls = [];
        foreach ($this->sitemap->entries() as $entry) {
            foreach ($entry['locales'] as $href) {
                $urls[] = $href;
            }
        }

        return array_values(array_unique($urls));
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('app.marketing_url'), '/');
    }

    /** @return list<string> */
    protected function urlsFromFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml) {
            return [];
        }

        $urls = [];
        foreach ($xml->url ?? [] as $u) {
            $loc = (string) $u->loc;
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }

        return $urls;
    }
}
