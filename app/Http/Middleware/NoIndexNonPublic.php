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

        $isPublicPage = $request->is('welcome') || $request->is('welcome/*');
        $isRobots = $request->is('robots.txt');

        if (! $isPublicPage && ! $isRobots) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
