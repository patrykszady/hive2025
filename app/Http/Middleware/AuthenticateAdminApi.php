<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for /api/admin/v1 — the management API ss-systems
 * (and only ss-systems) talks to on this app's behalf.
 *
 * Ported from gsc's/dawnsellshomes' identical middleware: a single trusted
 * server-to-server caller, so a constant-time comparison against one
 * configured token is enough. No Sanctum — there is no per-user identity
 * to model here, just "is this ss-systems".
 */
class AuthenticateAdminApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.admin_api.token');

        if ($expected === '') {
            return response()->json([
                'message' => 'The admin API is not enabled on this server.',
            ], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
