<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-response cache for static public pages (the /{locale}/welcome
 * marketing set). Rendering those Blade trees costs ~200ms; serving the
 * cached HTML costs ~1ms.
 *
 * Guests only and GET only: any authenticated visit (e.g. an admin previewing
 * while logged in) bypasses the cache entirely.
 *
 * These pages are NOT free of per-session data, despite carrying no component
 * snapshot: @livewireScripts stamps the visitor's CSRF token into the script
 * tag's data-csrf (and the head's meta tag). Cached as-is, every guest was
 * handed the token belonging to whoever warmed the cache — harmless on the
 * marketing page itself, fatal one wire:navigate later: Livewire reads that
 * token once at boot and keeps it across SPA navigation, so typing in the
 * login page's email field POSTed a stranger's token and got 419 Page
 * Expired on every keystroke. So the body is cached once and its tokens are
 * rewritten to the current session's on the way out.
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

        return response($this->withCurrentCsrfToken($html, $request))
            ->header('X-Page-Cache', 'hit');
    }

    /**
     * Swap the cached body's CSRF token for this visitor's own. Both places
     * Livewire and Blade publish it are rewritten; a page that carries
     * neither is returned untouched.
     */
    protected function withCurrentCsrfToken(string $html, Request $request): string
    {
        $session = $request->hasSession() ? $request->session() : null;

        if (! $session) {
            return $html;
        }

        $token = $session->token();

        return preg_replace(
            [
                '/data-csrf="[^"]*"/',
                '/(<meta name="csrf-token" content=")[^"]*(")/',
            ],
            [
                'data-csrf="'.$token.'"',
                '${1}'.$token.'${2}',
            ],
            $html
        ) ?? $html;
    }
}
