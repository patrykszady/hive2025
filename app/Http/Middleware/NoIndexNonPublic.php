<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response on this host is noindex except the public marketing site:
 * the dashboard, the hub, every app page, their redirects to /login and
 * their 404s. Registered as global middleware (bootstrap/app.php) so no
 * route middleware that answers early can skip it.
 */
class NoIndexNonPublic
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A path-shaped match alone isn't enough for the blog: a draft's
        // slug lives at the exact same /{locale}/blog/{slug} shape as a
        // published one, and only 404s there — so a non-200 response under
        // a public path is never treated as public either, except a
        // redirect to another public page (the guest's '/' to /en/welcome,
        // the legacy /welcome/* links): a crawler must follow those freely.
        $public = $this->isPublicPage($request)
            && ($response->getStatusCode() === 200 || $this->redirectsToPublicPage($request, $response));

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
        return $this->isPublicPath($request->path());
    }

    /** A redirect on this host to one of the public pages. */
    protected function redirectsToPublicPage(Request $request, Response $response): bool
    {
        if (! $response->isRedirection()) {
            return false;
        }

        $location = (string) $response->headers->get('Location');
        $host = parse_url($location, PHP_URL_HOST);

        if ($location === '' || ($host !== null && $host !== false && strcasecmp($host, $request->getHost()) !== 0)) {
            return false;
        }

        $path = trim((string) parse_url($location, PHP_URL_PATH), '/');

        return $path !== '' && $this->isPublicPath($path);
    }

    protected function isPublicPath(string $path): bool
    {
        if ($path === '/' || $path === 'welcome' || str_starts_with($path, 'welcome/')) {
            return true;
        }

        $locales = array_map(
            fn (string $code) => preg_quote($code, '#'),
            array_keys(config('locales.supported', ['en' => []]))
        );

        $localeGroup = implode('|', $locales);

        $pattern = '#^('.$localeGroup.')/(welcome(/.*)?|blog(/[^/]+)?)$#';

        return (bool) preg_match($pattern, $path);
    }
}
