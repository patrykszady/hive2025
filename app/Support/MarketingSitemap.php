<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Builds the public marketing sitemap (routes/web.php's GET /sitemap.xml,
 * App\Http\Controllers\SitemapController) from the SAME sources the
 * marketing pages themselves render from — never a hand-written URL list:
 *
 *   - every GET route carrying the SetLocale middleware (the {locale}/welcome
 *     route group in routes/web.php) becomes one entry per supported locale;
 *   - the one parameterized route in that group, welcome.feature
 *     ({area}/{card}), is expanded against config('marketing.areas') — the
 *     exact same config the feature page itself reads its content from.
 *
 * Add a Route::view(...) under the {locale} group, or a card to
 * config/marketing.php, and it appears here with no further changes.
 *
 * Every URL is rooted on config('app.marketing_url') — the public marketing
 * host — never APP_URL/the ambient request host, so the sitemap stays
 * correct however the app itself is routed between hosts.
 */
class MarketingSitemap
{
    /**
     * @return list<array{locales: array<string, string>, lastmod: ?Carbon}>
     */
    public function entries(): array
    {
        $locales = array_keys(config('locales.supported', ['en' => []]));
        $entries = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! in_array(SetLocale::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if ($name === 'welcome.feature') {
                $lastmod = $this->lastModForFile(resource_path('views/welcome/feature.blade.php'))
                    ?? $this->lastModForFile(config_path('marketing.php'));

                foreach (config('marketing.areas', []) as $areaKey => $area) {
                    foreach (array_keys($area['cards'] ?? []) as $cardKey) {
                        $entries[] = $this->localizedEntry(
                            $locales,
                            $name,
                            ['area' => $areaKey, 'card' => $cardKey],
                            $lastmod
                        );
                    }
                }

                continue;
            }

            $extraParams = array_diff($route->parameterNames(), ['locale']);

            if (! empty($extraParams)) {
                // A localizable route with parameters we don't know how to
                // expand — skip rather than guess. Keeps the sitemap honest
                // instead of emitting a URL Laravel can't actually generate.
                continue;
            }

            $entries[] = $this->localizedEntry($locales, $name, [], $this->lastModForRoute($route));
        }

        return $entries;
    }

    /**
     * @param  list<string>  $locales
     * @param  array<string, string>  $params
     * @return array{locales: array<string, string>, lastmod: ?Carbon}
     */
    protected function localizedEntry(array $locales, string $routeName, array $params, ?Carbon $lastmod): array
    {
        $urls = [];

        foreach ($locales as $locale) {
            // Generate the relative path (absolute = false): host-independent
            // by construction, then rooted on the marketing host ourselves.
            $path = route($routeName, array_merge(['locale' => $locale], $params), false);
            $urls[$locale] = marketing_url($path);
        }

        return ['locales' => $urls, 'lastmod' => $lastmod];
    }

    protected function lastModForRoute($route): ?Carbon
    {
        $view = $route->defaults['view'] ?? null;

        if (! $view) {
            return null;
        }

        return $this->lastModForFile($this->viewPath($view));
    }

    protected function viewPath(string $view): ?string
    {
        try {
            $path = view($view)->getPath();

            return $path ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function lastModForFile(?string $path): ?Carbon
    {
        if (! $path || ! is_file($path)) {
            return null;
        }

        return Carbon::createFromTimestamp(filemtime($path));
    }
}
