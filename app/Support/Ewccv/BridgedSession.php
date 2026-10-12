<?php

namespace App\Support\Ewccv;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

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
 *
 * Kept in a file on the local disk, not in the cache: prod caches to files
 * and every deploy's optimize:clear emptied them, so each deploy on
 * 2026-10-12 threw away the session a person had just handed over.
 */
class BridgedSession
{
    public const MAX_AGE_MINUTES = 45;

    public const FILE = 'ewccv/bridged-session.json';

    /** How long a stored session is kept at all; past this it is dead weight. */
    public const KEEP_HOURS = 12;

    /**
     * @param  array{access_token: string, jwt?: ?string, state_token?: ?string}  $session
     */
    public static function store(array $session): void
    {
        Storage::disk('local')->put(self::FILE, json_encode([
            'access_token' => (string) $session['access_token'],
            'jwt' => $session['jwt'] ?? null,
            'state_token' => $session['state_token'] ?? null,
            'received_at' => now()->toIso8601String(),
        ]));
    }

    public static function forget(): void
    {
        Storage::disk('local')->delete(self::FILE);
    }

    /**
     * @return array{access_token: string, jwt: ?string, state_token: ?string, received_at: string}|null
     */
    public static function raw(): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::FILE)) {
            return null;
        }
        $session = json_decode((string) $disk->get(self::FILE), true);
        if (! is_array($session) || empty($session['access_token']) || empty($session['received_at'])) {
            return null;
        }
        if (Carbon::parse($session['received_at'])->lt(now()->subHours(self::KEEP_HOURS))) {
            return null;
        }

        return [
            'access_token' => (string) $session['access_token'],
            'jwt' => $session['jwt'] ?? null,
            'state_token' => $session['state_token'] ?? null,
            'received_at' => (string) $session['received_at'],
        ];
    }

    /**
     * @return array{access_token: string, jwt: ?string, state_token: ?string, received_at: string, age_minutes: int}|null
     */
    public static function fresh(): ?array
    {
        $session = self::raw();
        if (! $session) {
            return null;
        }

        $age = (int) Carbon::parse($session['received_at'])->diffInMinutes(now());
        if ($age > self::MAX_AGE_MINUTES) {
            return null;
        }

        return $session + ['age_minutes' => $age];
    }

    /** Whether any credential path exists for an unattended search right now. */
    public static function canSearch(): bool
    {
        return self::fresh() !== null || filled(config('services.ewccv.bypass_key'));
    }
}
