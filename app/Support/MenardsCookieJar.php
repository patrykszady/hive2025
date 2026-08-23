<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The menards.com session borrowed from a real browser.
 *
 * Why this exists: the scraper's own sign-in stopped working in August 2026 —
 * not because the captcha stopped being solved (anti-captcha still returns a
 * valid hCaptcha token) but because Imperva scores the *session* and refuses an
 * automated one regardless. Same class of wall as DataDome on biz.yelp.com,
 * and the same answer: stop trying to look human, and borrow the session a
 * human already has.
 */
class MenardsCookieJar
{
    public const CACHE_KEY = 'menards.session_jar';

    /** Menards' own session cookie; without it the jar is just Imperva state. */
    protected const AUTH_COOKIES = ['JSESSIONID', 'MENARDS_SESSION', 'guestSession', 'SESSION'];

    /** Imperva's per-session cookies. Present on any visit, signed-in or not. */
    protected const WALL_COOKIES = ['incap_ses_', 'visid_incap_', 'nlbi_', 'reese84'];

    /**
     * Coerce whatever the extension sent into Puppeteer's cookie shape,
     * dropping anything that is not a menards.com cookie.
     *
     * @return array{cookies: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public static function normalize(mixed $payload): array
    {
        $raw = is_array($payload) ? ($payload['cookies'] ?? $payload) : [];
        $cookies = [];
        $stats = ['received' => 0, 'kept' => 0, 'foreign' => 0, 'malformed' => 0];

        foreach ((array) $raw as $cookie) {
            $stats['received']++;

            if (! is_array($cookie) || ! isset($cookie['name'], $cookie['value'])) {
                $stats['malformed']++;

                continue;
            }

            $domain = (string) ($cookie['domain'] ?? '');

            if ($domain !== '' && ! str_contains($domain, 'menards.com')) {
                $stats['foreign']++;

                continue;
            }

            $cookies[] = array_filter([
                'name' => (string) $cookie['name'],
                'value' => (string) $cookie['value'],
                'domain' => $domain !== '' ? $domain : '.menards.com',
                'path' => (string) ($cookie['path'] ?? '/'),
                'httpOnly' => (bool) ($cookie['httpOnly'] ?? false),
                'secure' => (bool) ($cookie['secure'] ?? true),
                // Chrome sends seconds; Puppeteer wants the same. Session
                // cookies have no expiry and must stay that way.
                'expires' => isset($cookie['expirationDate']) ? (int) $cookie['expirationDate'] : null,
            ], static fn ($v) => $v !== null);

            $stats['kept']++;
        }

        return ['cookies' => $cookies, 'stats' => $stats];
    }

    /**
     * Does this jar plausibly represent a signed-in session?
     *
     * Imperva cookies alone mean "this browser visited menards.com", not
     * "this browser is logged in" — handing that to the scraper would fail
     * 90 seconds into a Chromium run, long after the extension could say so.
     */
    public static function looksAuthenticated(array $cookies): bool
    {
        foreach ($cookies as $cookie) {
            foreach (self::AUTH_COOKIES as $name) {
                if (strcasecmp((string) ($cookie['name'] ?? ''), $name) === 0 && ($cookie['value'] ?? '') !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public static function wallCookiesPresent(array $cookies): array
    {
        $found = [];

        foreach ($cookies as $cookie) {
            $name = (string) ($cookie['name'] ?? '');
            foreach (self::WALL_COOKIES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $found[] = $name;
                }
            }
        }

        return array_values(array_unique($found));
    }

    public static function put(array $cookies): void
    {
        // Long enough to cover a weekend of scheduled runs; short enough that a
        // dead jar cannot linger and make every run look like a fresh failure.
        Cache::put(self::CACHE_KEY, [
            'cookies' => $cookies,
            'received_at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }

    /** @return array<int, array<string, mixed>> */
    public static function get(): array
    {
        return (array) (Cache::get(self::CACHE_KEY)['cookies'] ?? []);
    }

    public static function receivedAt(): ?string
    {
        return Cache::get(self::CACHE_KEY)['received_at'] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
