<?php

namespace App\Http\Controllers;

use App\Support\MenardsCookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives a menards.com session from the browser extension.
 *
 * The scraper's own sign-in stopped working in August 2026. The captcha is not
 * the problem — anti-captcha still solves the hCaptcha and returns a valid
 * token — but Imperva then refuses the session anyway, scoring the automated
 * browser rather than the challenge. The 2026-08-18 runs show it plainly:
 * "hCaptcha solved — token length: 1034" followed by "Imperva wall persisted
 * after 3 challenge attempts".
 *
 * So the server stops trying to look like a person and borrows the session a
 * person already has. Same play as the Yelp cookie bridge on gs.construction
 * (DataDome) and the EWCCV session bridge here (reCAPTCHA Enterprise).
 *
 * Called by an MV3 service worker, which carries no session and no CSRF token:
 * auth is a bearer token compared in constant time.
 */
class MenardsSessionController extends Controller
{
    /** A real jar is a few KB; reject anything absurd before decoding. */
    protected const MAX_BYTES = 256 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        // Via config(), never env() — `php artisan config:cache` runs on deploy
        // and env() returns null there.
        $expected = (string) config('services.menards.bridge_token');

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Menards session bridge is not configured on this server.',
            ], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::channel('menards')->warning('Menards bridge: bad token', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        ['cookies' => $cookies, 'stats' => $stats] = MenardsCookieJar::normalize(
            $request->input('cookies', $request->json()->all())
        );

        if ($cookies === []) {
            Log::channel('menards')->warning('Menards bridge: nothing usable in payload', $stats);

            return response()->json([
                'ok' => false,
                'error' => 'No usable menards.com cookies in payload.',
                'stats' => $stats,
            ], 422);
        }

        // Say this now, to the extension, where it can be shown to the person
        // who can fix it — rather than 90 seconds into the next scheduled run.
        if (! MenardsCookieJar::looksAuthenticated($cookies)) {
            return response()->json([
                'ok' => false,
                'error' => 'Those cookies are not a signed-in session — visit menards.com and sign in first, then send again.',
                'stats' => $stats,
            ], 422);
        }

        MenardsCookieJar::put($cookies);

        Log::channel('menards')->info('Menards bridge: session received', $stats + [
            'wall_cookies' => MenardsCookieJar::wallCookiesPresent($cookies),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Menards session stored.',
            'stats' => $stats,
            'expires_in_days' => 7,
        ]);
    }
}
