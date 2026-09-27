<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enumerates the marketing site's pages for ss-systems' Pages screen —
 * built from the SAME sources App\Support\MarketingSitemap reads (every
 * route under the {locale} group, welcome.feature expanded against
 * config('marketing.areas')), never a hand-typed list, plus the two legal
 * pages, which sitemap.xml doesn't carry (they're outside the {locale}
 * group — see routes/web.php — so they're absent from every locale's
 * sitemap entry) but which this admin screen still needs to manage.
 *
 * One entry per page in English only (`Pages screen: the site's pages once
 * each, English, not per locale` — see api-admin/pages.php's docblock), so
 * unlike MarketingSitemap this never loops over locales.
 */
class MarketingPages
{
    public const TYPES = ['area', 'feature', 'homeowners', 'legal', 'faq', 'other'];

    /**
     * @return list<array{route_name: string, params: array<string, string>, type: string, view: ?string}>
     */
    public static function all(): array
    {
        $pages = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            // The blog's index and posts belong to the Blog screen, not here.
            if ($name === null || Str::startsWith($name, 'blog.')) {
                continue;
            }

            $isLocalized = in_array(SetLocale::class, $route->gatherMiddleware(), true);
            $isLegal = Str::startsWith($name, 'legal.');

            if (! $isLocalized && ! $isLegal) {
                continue;
            }

            if ($name === 'welcome.feature') {
                foreach (config('marketing.areas', []) as $areaKey => $area) {
                    foreach (array_keys($area['cards'] ?? []) as $cardKey) {
                        $pages[] = [
                            'route_name' => $name,
                            'params' => ['area' => $areaKey, 'card' => $cardKey],
                            'type' => 'feature',
                            'view' => 'welcome.feature',
                        ];
                    }
                }

                continue;
            }

            $extraParams = array_diff($route->parameterNames(), ['locale']);

            if (! empty($extraParams)) {
                // No other parameterized marketing route to expand — keep
                // this honest rather than guessing, same reasoning as
                // MarketingSitemap::entries().
                continue;
            }

            $pages[] = [
                'route_name' => $name,
                'params' => [],
                'type' => self::typeFor($name),
                'view' => $route->defaults['view'] ?? null,
            ];
        }

        return $pages;
    }

    protected static function typeFor(string $name): string
    {
        return match (true) {
            $name === 'welcome' => 'other',
            $name === 'welcome.faq' => 'faq',
            Str::startsWith($name, 'welcome.homeowners') => 'homeowners',
            Str::startsWith($name, 'legal.') => 'legal',
            Str::startsWith($name, 'welcome.') => 'area',
            default => 'other',
        };
    }

    /**
     * The page's own title before any admin override — resolved from the
     * exact string its Blade view passes to @section('title', ...) (a plain
     * quoted literal or a __()-wrapped one; every welcome/legal view uses
     * one of those two forms), cheaply via a regex over the file rather
     * than a full Blade render. welcome.feature is special-cased to match
     * feature.blade.php's own formula exactly. A view with no @section
     * ('title', ...) at all (the legal pages) falls back to its first
     * <h1>'s text.
     *
     * @param  array<string, string>  $params
     */
    public static function baseTitle(string $routeName, array $params, ?string $view): ?string
    {
        if ($routeName === 'welcome.feature') {
            $card = config("marketing.areas.{$params['area']}.cards.{$params['card']}", []);

            return isset($card['title']) ? $card['title'].' — Hive Contractors' : null;
        }

        $path = self::viewPath($view);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $source = file_get_contents($path) ?: '';

        if (preg_match('/@section\(\s*[\'"]title[\'"]\s*,\s*(.*?)\)\s*\r?\n/', $source, $m)) {
            $expr = trim($m[1]);

            if (preg_match('/^__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)$/', $expr, $mm)
                || preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $expr, $mm)
                || preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $expr, $mm)) {
                return stripcslashes($mm[1]);
            }
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $source, $m)) {
            return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }

        return null;
    }

    /** The view file's own mtime — MarketingSitemap's own notion of "last modified" for a page. */
    public static function lastModified(string $routeName, array $params, ?string $view): ?int
    {
        $path = $routeName === 'welcome.feature'
            ? resource_path('views/welcome/feature.blade.php')
            : self::viewPath($view);

        return ($path && is_file($path)) ? filemtime($path) : null;
    }

    protected static function viewPath(?string $view): ?string
    {
        if ($view === null) {
            return null;
        }

        try {
            $path = view($view)->getPath();

            return $path ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The route's own path, English (locale 'en' for a localized route),
     * relative — never absolute: callers root it themselves
     * (marketing_url()) rather than trust the ambient request host.
     *
     * @param  array<string, string>  $params
     */
    public static function path(string $routeName, array $params): string
    {
        $isLegal = Str::startsWith($routeName, 'legal.');
        $routeParams = $isLegal ? $params : array_merge(['locale' => 'en'], $params);

        return ltrim(route($routeName, $routeParams, false), '/');
    }
}
