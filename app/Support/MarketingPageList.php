<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The marketing site's titled English pages in the one shape every AI feed
 * lists them in: /llms.txt, /llms-full.txt and /ai-feed.json's `stories`
 * all read this, so a page appears in all three together or in none.
 * Built on App\Support\MarketingPages (the route/config registry the
 * sitemap and the hub's Pages screen read), never a hand-typed list — the
 * home page, the feature-area pages, the homeowner pages and the FAQ, each
 * with the title its own <title> tag carries and the description its own
 * <meta> carries. The per-card feature pages are left out, as /llms.txt
 * always has: config('marketing.areas') carries them in full, and
 * App\Support\Seo\AiFeed\HiveServiceCatalog lists them under their area.
 */
class MarketingPageList
{
    public const FALLBACK_DESCRIPTION = 'Hive Contractors is the CRM built for small contractors: finances, estimates, leads and clients, vendors and compliance, planning, team time, communication and photos in one app.';

    /**
     * @return list<array{route_name: string, type: string, title: string, url: string, description: ?string}>
     */
    public static function english(): array
    {
        return self::inEnglish(fn (): array => collect(MarketingPages::all())
            ->filter(fn (array $page): bool => $page['route_name'] !== 'welcome.feature' && in_array($page['type'], ['other', 'area', 'homeowners', 'faq'], true))
            ->map(fn (array $page): array => [
                'route_name' => $page['route_name'],
                'type' => $page['type'],
                'title' => self::title($page),
                'url' => marketing_url(MarketingPages::path($page['route_name'], $page['params'])),
                'description' => MarketingPages::description($page['route_name'], $page['params'], $page['view']),
            ])
            ->filter(fn (array $page): bool => $page['title'] !== null)
            ->sortBy(fn (array $page): string => $page['route_name'] === 'welcome' ? '' : $page['url'])
            ->values()
            ->all());
    }

    /** The home page's own meta description — the one line every feed opens with. */
    public static function homeDescription(): string
    {
        return self::inEnglish(fn (): string => MarketingPages::description('welcome', [], 'welcome') ?? self::FALLBACK_DESCRIPTION);
    }

    /**
     * The site's other languages (config/locales.php), for every feed's
     * "Languages" section: the default locale is the English the feeds are
     * written in.
     *
     * @return list<array{locale: string, native: string, name: string, url: string}>
     */
    public static function otherLanguages(): array
    {
        $default = (string) config('locales.default', 'en');
        $languages = [];

        foreach ((array) config('locales.supported', []) as $locale => $meta) {
            if ($locale === $default) {
                continue;
            }

            $languages[] = [
                'locale' => (string) $locale,
                'native' => (string) ($meta['native'] ?? $locale),
                'name' => (string) ($meta['name'] ?? $locale),
                'url' => marketing_url("{$locale}/welcome"),
            ];
        }

        return $languages;
    }

    /**
     * Runs $read with the app locale pinned to English and puts the
     * previous locale back: the titles and descriptions go through __(),
     * and these feeds are English however the request arrived.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function inEnglish(callable $read): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale('en');

        try {
            return $read();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The page's own title without the " — Hive Contractors" suffix its
     * <title> tag carries; null for a page with no title of its own.
     *
     * @param  array{route_name: string, params: array<string, string>, view: ?string}  $page
     */
    public static function title(array $page): ?string
    {
        $title = MarketingPages::baseTitle($page['route_name'], $page['params'], $page['view']);

        if ($title === null) {
            return null;
        }

        $title = html_entity_decode(__($title));

        return trim(Str::before($title, ' — Hive Contractors')) ?: null;
    }
}
