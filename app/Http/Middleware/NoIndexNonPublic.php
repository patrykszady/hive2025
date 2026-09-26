<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoIndexNonPublic
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isPublicPage($request) && ! $request->is('robots.txt') && ! $request->is('sitemap.xml')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }

    /**
     * The marketing site's own public paths: the root redirect, the bare
     * (un-prefixed, pre-locale-migration) /welcome/* legacy redirects and
     * legal pages, and every locale's /{locale}/welcome tree.
     *
     * This used to check only 'welcome' / 'welcome/*' — written before the
     * marketing pages moved under a required {locale} prefix (routes/web.php)
     * — which meant every real marketing page (/en/welcome, /pl/welcome/...)
     * fell through to the noindex branch below and shipped
     * "X-Robots-Tag: noindex" on every response, invisibly de-indexing the
     * entire public site regardless of robots.txt or the sitemap.
     */
    protected function isPublicPage(Request $request): bool
    {
        if ($request->is('/') || $request->is('welcome') || $request->is('welcome/*')) {
            return true;
        }

        $locales = array_map(
            fn (string $code) => preg_quote($code, '#'),
            array_keys(config('locales.supported', ['en' => []]))
        );

        $pattern = '#^('.implode('|', $locales).')/welcome(/.*)?$#';

        return (bool) preg_match($pattern, $request->path());
    }
}
