<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\PageSeoOverride;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Throwable;

/**
 * What components/layouts/head.blade.php actually renders for the current
 * request — the read side of the Pages/Services admin screens' override:
 * a null-fast lookup (no query at all off a marketing/legal route) so this
 * costs nothing on every other page in the app, and one indexed query on
 * the pages it does apply to. Only reached on a CachePublicPage cache MISS
 * (the override is baked into the cached HTML at write time), so even that
 * query is rare in practice.
 *
 * The query is wrapped in a try/catch: a page must render with its own
 * default title rather than 500 the instant the table is briefly
 * unavailable (mid-`migrate`, a test with no RefreshDatabase) — the same
 * "never let optional data break the page" instinct as every ping-domain
 * fallback elsewhere in this app.
 */
class PageSeo
{
    /** @return ?array{title: ?string, meta_title: ?string, meta_description: ?string} */
    public static function current(): ?array
    {
        $route = RouteFacade::current();

        if (! $route || ! $route->getName()) {
            return null;
        }

        $name = $route->getName();
        $isLocalized = in_array(SetLocale::class, $route->gatherMiddleware(), true);
        $isLegal = Str::startsWith($name, 'legal.');

        if (! $isLocalized && ! $isLegal) {
            return null;
        }

        // parameterNames() is the URI's OWN wildcard segments (['locale'] for
        // a plain welcome.* route, ['locale', 'area', 'card'] for
        // welcome.feature) — parameters() itself is not enough: Route::view()
        // (every welcome.*/legal.* route except welcome.feature) also
        // exposes its 'view'/'data'/'status'/'headers' defaults through
        // parameters(), which would corrupt the params key. Same technique
        // MarketingPages::all() uses to decide what to expand.
        $paramNames = array_diff($route->parameterNames(), ['locale']);
        $params = array_intersect_key($route->parameters(), array_flip($paramNames));

        try {
            $override = PageSeoOverride::findFor($name, $params);
        } catch (Throwable) {
            return null;
        }

        if (! $override) {
            return null;
        }

        return [
            'title' => $override->title,
            'meta_title' => $override->meta_title,
            'meta_description' => $override->meta_description,
        ];
    }
}
