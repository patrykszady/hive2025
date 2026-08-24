<?php

namespace App\Http\Controllers;

use App\Services\MenardsCaptchaSolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Buys an hCaptcha token for the extension's challenge.js.
 *
 * The key lives here, not in the extension: a content script's source is
 * readable by the page it runs in, and the background worker's is readable by
 * anyone with the profile. The browser reports a sitekey; the server decides
 * whether to spend money on it.
 *
 * READ MenardsCaptchaSolver's docblock before trusting any of this. The same
 * approach already succeeded at solving and still failed to get in — Imperva
 * scores the browser, not the token.
 */
class MenardsSolveChallengeController extends Controller
{
    /**
     * Every solve costs money, so this is rate-limited by more than the route's
     * throttle. A wall that is refusing the BROWSER will keep re-challenging
     * forever, and without this a challenge loop would quietly spend the
     * balance overnight.
     */
    public const MAX_PER_HOUR = 4;

    /** Public so the cap can be inspected and cleared from tests and tinker. */
    public const COUNTER_KEY = 'menards:challenge-solves';

    public function __invoke(Request $request, MenardsCaptchaSolver $solver): JsonResponse
    {
        $expected = (string) config('services.menards.bridge_token');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'Menards bridge is not configured.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::channel('menards')->warning('Menards solve: bad token', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'siteKey' => 'required|string|max:100',
            'pageUrl' => 'required|url|max:500',
        ]);

        if (! $solver->configured()) {
            return response()->json(['ok' => false, 'error' => 'No captcha solver is configured.'], 503);
        }

        $spent = (int) Cache::get(self::COUNTER_KEY, 0);

        if ($spent >= self::MAX_PER_HOUR) {
            Log::channel('menards')->warning('Menards solve: hourly cap reached, refusing to spend more', [
                'cap' => self::MAX_PER_HOUR,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Hourly solve limit reached. The wall is most likely refusing the browser, not the token.',
            ], 429);
        }

        // Count the ATTEMPT, not the success: 2captcha charges for solves that
        // Imperva then rejects, which is precisely the loop this cap exists to
        // stop.
        Cache::put(self::COUNTER_KEY, $spent + 1, now()->addHour());

        $result = $solver->solve($validated['siteKey'], $validated['pageUrl']);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'Solve failed.',
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'token' => $result['token'],
            'seconds' => $result['seconds'] ?? null,
        ]);
    }
}
