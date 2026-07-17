<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale handling for the public marketing site. The optional {locale}
 * route-prefix segment carries a supported non-default locale (/pl/welcome,
 * /es/welcome); the default locale ('en') carries no prefix. This middleware:
 *   - 404s an unsupported or redundant (/en/...) prefix so there is exactly
 *     one canonical URL per page,
 *   - sets App::getLocale() to match, and
 *   - pins URL::defaults('locale') so every route() call in the request
 *     regenerates the current locale's prefix — keeping all existing
 *     route('welcome') links language-correct with no call-site changes.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The {locale} segment is constrained to supported codes by the route's
        // where() clause, so it is always valid here; fall back defensively.
        $locale = $request->route('locale') ?? config('locales.default', 'en');

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        return $next($request);
    }
}
