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

    /** Hashes of the credentials handed over so far, newest last. */
    public const SEEN_FILE = 'ewccv/seen-tokens.json';

    public const SEEN_KEEP = 40;

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
        self::remember((string) $session['access_token']);
    }

    /**
     * Has this exact credential been handed over before? The browser's minter
     * falls back to the copy its page already holds when minting fails, and
     * the extension sends on every page load: the same credential arrived
     * again and again on 2026-10-12, each time "0 min old" and already spent
     * by the run that used it first. One that was handed over once is never
     * worth taking twice; a genuinely new one (after a search by hand) is.
     */
    public static function hasSeen(string $accessToken): bool
    {
        return in_array(self::hash($accessToken), self::seen(), true);
    }

    protected static function remember(string $accessToken): void
    {
        $seen = array_values(array_unique([...self::seen(), self::hash($accessToken)]));
        Storage::disk('local')->put(self::SEEN_FILE, json_encode(array_slice($seen, -self::SEEN_KEEP)));
    }

    /** @return list<string> */
    protected static function seen(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::SEEN_FILE)) {
            return [];
        }
        $seen = json_decode((string) $disk->get(self::SEEN_FILE), true);

        return is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [];
    }

    protected static function hash(string $accessToken): string
    {
        return hash('sha256', $accessToken);
    }

    /**
     * Drop the stored session — only the one a run used (by its received_at)
     * when given: a fresh hand-off can land while a run is finishing, and on
     * 2026-10-12 the run then deleted the fresh session instead of its own
     * spent one.
     */
    public static function forget(?string $receivedAt = null): void
    {
        if ($receivedAt !== null) {
            $current = self::raw();
            if ($current && $current['received_at'] !== $receivedAt) {
                return;
            }
        }
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
