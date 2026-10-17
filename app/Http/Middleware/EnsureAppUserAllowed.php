<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate on the Hive Contractors app's authenticated routes: only GS
 * Construction's own team (primary_vendor_id 1) may use the app, even with
 * an otherwise-valid "app"-ability token — matches the sign-in rule in
 * Api\App\V1\AuthController, so a token issued before a team member is
 * reassigned off vendor 1 stops working immediately rather than at its
 * next login.
 */
class EnsureAppUserAllowed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (int) $user->primary_vendor_id !== 1) {
            abort(403, 'This account does not have access to the Hive Contractors app.');
        }

        return $next($request);
    }
}
