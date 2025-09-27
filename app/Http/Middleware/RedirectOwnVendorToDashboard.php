<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectOwnVendorToDashboard
{
    /**
     * If the requested vendor is the authenticated user's primary vendor, redirect to dashboard.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if (!$user) {
            return $next($request);
        }

        $routeVendor = $request->route('vendor');
        if ($routeVendor && $user->vendor && $routeVendor->id === $user->vendor->id) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
