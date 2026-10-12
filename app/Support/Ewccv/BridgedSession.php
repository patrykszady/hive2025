<?php

namespace App\Support\Ewccv;

use App\Http\Controllers\EwccvSessionController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The EWCCV search session a person's browser handed over through the
 * session-bridge extension (EwccvSessionController), when it is still
 * usable.
 *
 * ewccv.com issues its search credential (accessToken) only after a
 * reCAPTCHA v3 Enterprise verify, and every automated browser is refused —
 * measured from the droplet, through Bright Data, and from a home
 * residential line (2026-10-12). A real person's browser passes, so that is
 * the only credential the server runs searches with. EWCCV's accessToken is
 * session-scoped and short-lived; past MAX_AGE_MINUTES it only fails
 * searches a fresh one would complete.
 */
class BridgedSession
{
    public const MAX_AGE_MINUTES = 45;

    /**
     * @return array{access_token: string, jwt: ?string, state_token: ?string, received_at: string, age_minutes: int}|null
     */
    public static function fresh(): ?array
    {
        $session = Cache::get(EwccvSessionController::CACHE_KEY);
        if (! is_array($session) || empty($session['access_token']) || empty($session['received_at'])) {
            return null;
        }

        $age = (int) Carbon::parse($session['received_at'])->diffInMinutes(now());
        if ($age > self::MAX_AGE_MINUTES) {
            return null;
        }

        return [
            'access_token' => (string) $session['access_token'],
            'jwt' => $session['jwt'] ?? null,
            'state_token' => $session['state_token'] ?? null,
            'received_at' => (string) $session['received_at'],
            'age_minutes' => $age,
        ];
    }

    /** Whether any credential path exists for an unattended search right now. */
    public static function canSearch(): bool
    {
        return self::fresh() !== null || filled(config('services.ewccv.bypass_key'));
    }
}
