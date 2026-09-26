<?php

namespace App\Http\Controllers;

use App\Support\MarketingSitemap;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;

/**
 * GET /sitemap.xml — every public marketing URL, in every supported locale,
 * built by App\Support\MarketingSitemap from the actual route definitions
 * and config/marketing.php (see that class for how). Cached: the routes and
 * config it's built from only change on deploy.
 */
class SitemapController extends Controller
{
    protected const XHTML_NS = 'http://www.w3.org/1999/xhtml';

    public function __invoke(MarketingSitemap $sitemap): Response
    {
        $xml = Cache::remember(
            'marketing-sitemap-xml',
            now()->addHours(24),
            fn () => $this->render($sitemap->entries())
        );

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  list<array{locales: array<string, string>, lastmod: ?\Illuminate\Support\Carbon}>  $entries
     */
    protected function render(array $entries): string
    {
        $root = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            .'xmlns:xhtml="'.self::XHTML_NS.'"/>'
        );

        $defaultLocale = 'en';

        foreach ($entries as $entry) {
            foreach ($entry['locales'] as $href) {
                $url = $root->addChild('url');
                $url->addChild('loc', htmlspecialchars($href, ENT_XML1 | ENT_QUOTES));

                if ($entry['lastmod']) {
                    $url->addChild('lastmod', $entry['lastmod']->toDateString());
                }

                foreach ($entry['locales'] as $altLocale => $altHref) {
                    $this->addAlternate($url, $altLocale, $altHref);
                }

                // x-default is explicitly English — see head.blade.php's
                // matching hreflang tag for why this isn't
                // config('locales.default').
                $defaultHref = $entry['locales'][$defaultLocale] ?? reset($entry['locales']);
                $this->addAlternate($url, 'x-default', $defaultHref);
            }
        }

        return $root->asXML();
    }

    protected function addAlternate(SimpleXMLElement $url, string $hreflang, string $href): void
    {
        $link = $url->addChild('link', null, self::XHTML_NS);
        $link->addAttribute('rel', 'alternate');
        $link->addAttribute('hreflang', $hreflang);
        $link->addAttribute('href', $href);
    }
}
