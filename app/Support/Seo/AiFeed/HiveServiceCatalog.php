<?php

namespace App\Support\Seo\AiFeed;

use App\Support\MarketingPageList;
use App\Support\MarketingPages;
use Illuminate\Support\Str;
use SsSystems\Platform\Seo\AiFeed\Contracts\ServiceCatalogFeed;

/**
 * This site's `services` for /ai-feed.json: a software product has no
 * trade catalog, so its "services" are the product areas the marketing
 * site is built around — every area in config/marketing.php (the one
 * source the feature pages, the sitemap and the hub's Pages screen read)
 * with its English page, the page's own meta description and its feature
 * cards, plus the homeowner portal, whose pages live as routes rather
 * than cards. English only: the catalog contract has no place for a
 * page's other languages, and /sitemap.xml already carries the hreflang
 * alternates.
 */
final class HiveServiceCatalog implements ServiceCatalogFeed
{
    public function services(): array
    {
        return MarketingPageList::inEnglish(function (): array {
            $rows = [];

            foreach ((array) config('marketing.areas', []) as $areaKey => $area) {
                $rows[] = [
                    'slug' => $areaKey,
                    'name' => self::text((string) ($area['label'] ?? Str::headline($areaKey))),
                    'url' => marketing_url("en/welcome/{$areaKey}"),
                    'description' => MarketingPages::description("welcome.{$areaKey}", [], "welcome.{$areaKey}"),
                    'features' => collect($area['cards'] ?? [])
                        ->map(fn (array $card, string $cardKey): array => self::row([
                            'slug' => $cardKey,
                            'name' => self::text((string) ($card['title'] ?? Str::headline($cardKey))),
                            'url' => marketing_url("en/welcome/{$areaKey}/{$cardKey}"),
                            'description' => self::text((string) ($card['body'] ?? $card['lead'] ?? '')),
                        ]))
                        ->values()
                        ->all(),
                ];
            }

            $rows[] = $this->homeownerPortal();

            return $rows;
        });
    }

    /**
     * The homeowner portal: /en/welcome/homeowners and its nine pages are
     * routes under the {locale} group (routes/web.php), not cards in
     * config/marketing.php, so they come from the page list instead.
     *
     * @return array<string, mixed>
     */
    private function homeownerPortal(): array
    {
        $pages = collect(MarketingPageList::english())->where('type', 'homeowners');
        $index = $pages->firstWhere('route_name', 'welcome.homeowners');

        return [
            'slug' => 'homeowners',
            'name' => 'Homeowner portal',
            'url' => marketing_url('en/welcome/homeowners'),
            'description' => $index['description'] ?? null,
            'features' => $pages
                ->reject(fn (array $page): bool => $page['route_name'] === 'welcome.homeowners')
                ->map(fn (array $page): array => self::row([
                    'slug' => Str::afterLast($page['route_name'], '.'),
                    'name' => $page['title'],
                    'url' => $page['url'],
                    'description' => $page['description'],
                ]))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function text(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }
}
