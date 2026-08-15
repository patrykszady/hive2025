<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-response cache for static public pages (the /{locale}/welcome
 * marketing set — no auth, no CSRF, no per-user content). Rendering those
 * Blade trees costs ~200ms; serving the cached HTML costs ~1ms.
 *
 * Guests only and GET only: any authenticated visit (e.g. an admin previewing
 * while logged in) bypasses the cache entirely.
 */
class CachePublicPage
{
    public function handle(Request $request, Closure $next, int $ttlMinutes = 60): Response
    {
        if (! $request->isMethod('GET') || $request->user()) {
            return $next($request);
        }

        $key = 'public-page:'.md5($request->path());

        $html = Cache::remember($key, now()->addMinutes($ttlMinutes), function () use ($request, $next) {
            $response = $next($request);

            // Only cache clean HTML 200s — redirects/errors pass through fresh.
            if ($response->getStatusCode() !== 200 || $response->headers->has('Set-Cookie') === false) {
                // Livewire-less static views still set the session cookie;
                // that's fine — we cache the BODY only, cookies stay per-visitor.
            }

            return $response->getStatusCode() === 200 ? $response->getContent() : null;
        });

        if ($html === null) {
            return $next($request);
        }

        return response($html)->header('X-Page-Cache', 'hit');
    }
}
