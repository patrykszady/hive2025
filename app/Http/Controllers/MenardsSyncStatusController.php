<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The extension reporting how its last fetch actually went.
 *
 * WHY THIS EXISTS
 *
 * Nothing on the server could tell a live Menards session from a dead one.
 * `signedIn()` infers it from the browser's window title, deliberately, because
 * the honest check — navigating to a Menards page — is what draws Imperva's
 * challenge and DESTROYED a working session on 2026-08-23 at 07:58. So the
 * health check could not be made honest without breaking the health.
 *
 * The extension already knows, for free: it makes a real authenticated XHR
 * every sync, and on 2026-08-23 it had recorded
 * "initialize.ajx returned HTML — the browser session has expired" at 08:01.
 * That truth simply never left the browser. Meanwhile `status` kept reporting
 * signed_in: yes and `ensure` kept saying "the session works" off a 25-hour
 * batch heuristic, through four consecutive dead scheduled runs.
 *
 * This is that report. No navigation, no extra request to Menards — just the
 * outcome of work the extension was doing anyway.
 */
class MenardsSyncStatusController extends Controller
{
    /** Key the browser service reads. Kept a month: far longer than any gap that matters. */
    public const CACHE_KEY = 'menards:last_sync_status';

    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.menards.bridge_token');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'Menards bridge is not configured.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::channel('menards')->warning('Menards sync-status: bad token', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'ok' => 'required|boolean',
            'error' => 'nullable|string|max:500',
            'receipts' => 'nullable|integer|min:0',
        ]);

        $sessionExpired = ! $validated['ok']
            && str_contains(mb_strtolower((string) ($validated['error'] ?? '')), 'session has expired');

        $status = [
            'ok' => (bool) $validated['ok'],
            'error' => $validated['error'] ?? null,
            'receipts' => $validated['receipts'] ?? null,
            'session_expired' => $sessionExpired,
            'at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_KEY, $status, now()->addMonth());

        // A dead session is the event worth seeing in the log — it is the one
        // that silently stops receipts arriving, and it is what four scheduled
        // runs failed to surface.
        if ($sessionExpired) {
            Log::channel('menards')->warning('Menards sync: session expired, sign-in needed', $status);
        } else {
            Log::channel('menards')->info('Menards sync: extension reported', $status);
        }

        return response()->json(['ok' => true]);
    }
}
