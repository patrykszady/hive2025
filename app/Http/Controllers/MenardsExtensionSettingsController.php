<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET api/menards/extension-settings — the schedule a PERSONAL browser's
 * receipt extension runs on (config services.menards.personal): how often it
 * checks, between which hours, in which time zone, and how far back it lists.
 *
 * The extension in Patryk's Edge is loaded unpacked and cannot update itself,
 * so anything we may want to tune lives here and is fetched on every run.
 * Same bearer token as the ingest and status endpoints.
 */
class MenardsExtensionSettingsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.menards.bridge_token');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'Menards bridge is not configured.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::channel('menards')->warning('Menards extension-settings: bad token', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        $personal = (array) config('services.menards.personal', []);

        return response()->json([
            'ok' => true,
            'interval_minutes' => max(5, (int) ($personal['interval_minutes'] ?? 60)),
            'window_start_hour' => (int) ($personal['window_start_hour'] ?? 7),
            'window_end_hour' => (int) ($personal['window_end_hour'] ?? 19),
            'timezone' => (string) ($personal['timezone'] ?? 'America/Chicago'),
            'lookback_days' => max(1, (int) ($personal['lookback_days'] ?? 14)),
        ]);
    }
}
