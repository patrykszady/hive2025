<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives an EWCCV search session from the browser extension.
 *
 * Why this exists: ewccv.com gates its search API behind reCAPTCHA v3
 * Enterprise, and every token this server can produce is rejected — provider
 * tokens (anti-captcha, 2captcha) AND tokens minted by EWCCV's own page inside
 * an automated Chrome. A control probe proved the tokens are structurally
 * valid (an invalid one comes back "MALFORMED", ours "No reason provided"),
 * so the wall is Google's score for an automated session, which no amount of
 * solver spend changes.
 *
 * Patryk's own browser has no such problem — real person, residential IP,
 * clean fingerprint. So the server stops trying to pass reCAPTCHA and instead
 * borrows the accessToken his browser already earned. Same play as the Yelp
 * session bridge on gs.construction, which solved the same class of problem.
 *
 * Called by an MV3 service worker, which carries no session and no CSRF token:
 * auth is a bearer token compared in constant time.
 */
class EwccvSessionController extends Controller
{
    /** Where the scraper looks for the handed-off session. */
    public const CACHE_KEY = 'ewccv.search_session';

    /** An accessToken is a JWT; a jar of them is still small. */
    protected const MAX_BYTES = 64 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.ewccv.bridge_token');

        if ($expected === '') {
            return response()->json(['error' => 'EWCCV bridge is not configured on the server.'], 503);
        }

        $provided = (string) $request->bearerToken();

        if (! hash_equals($expected, $provided)) {
            Log::warning('EWCCV bridge: bad token', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $data = $request->validate([
            'accessToken' => ['required', 'string', 'min:20', 'max:8000'],
            'jwt' => ['nullable', 'string', 'max:8000'],
            'stateToken' => ['nullable', 'string', 'max:200'],
        ]);

        // Keep only as long as it can plausibly be useful. The extension
        // refreshes on its own schedule, so a stale entry is replaced long
        // before this expires; the TTL exists so a dead token cannot linger.
        Cache::put(self::CACHE_KEY, [
            'access_token' => $data['accessToken'],
            'jwt' => $data['jwt'] ?? null,
            'state_token' => $data['stateToken'] ?? null,
            'received_at' => now()->toIso8601String(),
        ], now()->addHours(12));

        Log::info('EWCCV bridge: session received', [
            'access_token_len' => strlen($data['accessToken']),
            'has_jwt' => ! empty($data['jwt']),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'EWCCV search session stored.',
            'expires_in_hours' => 12,
        ]);
    }
}
