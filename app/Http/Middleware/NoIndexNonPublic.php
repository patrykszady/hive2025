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

        // A path-shaped match alone isn't enough for the blog: a draft's
        // slug lives at the exact same /{locale}/blog/{slug} shape as a
        // published one, and only 404s there — so a non-200 response
        // (that 404, but also any future redirect/error under a public
        // path) is never treated as public either.
        $public = $this->isPublicPage($request) && $response->getStatusCode() === 200;

        if (! $public && ! $request->is('robots.txt') && ! $request->is('sitemap.xml')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }

    /**
     * The marketing site's own public paths: the root redirect, the bare
     * (un-prefixed, pre-locale-migration) /welcome/* legacy redirects and
     * legal pages, every locale's /{locale}/welcome tree, and the blog
     * index/post pages (/{locale}/blog, /{locale}/blog/{slug} — a single
     * segment after "blog" only, so the signed /{locale}/blog/{slug}/
     * preview draft link never matches and always ships noindex).
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

        $localeGroup = implode('|', $locales);

        $pattern = '#^('.$localeGroup.')/(welcome(/.*)?|blog(/[^/]+)?)$#';

        return (bool) preg_match($pattern, $request->path());
    }
}
