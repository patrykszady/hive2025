<?php

namespace App\Support;

/**
 * Validates the `return_to` deep link the Hive Contractors app asks a
 * web-only flow (Nylas OAuth, Plaid Link) to redirect back to once it
 * finishes inside the system in-app browser (SFSafariViewController /
 * Chrome Custom Tabs via expo-web-browser's openAuthSessionAsync). Only the
 * app's own custom scheme is ever accepted — anything else (a bare
 * "https://…", a lookalike scheme, a second embedded "://") is rejected so
 * this never becomes an open redirect.
 */
final class AppReturnTo
{
    /** Matches app.json's "scheme" in the hive-contractors-app repo. */
    public const SCHEME = 'hivecontractors://';

    /**
     * Whether $url is safe to redirect() ->away() to: exactly the app's own
     * scheme, with no second "://" hiding another destination inside it.
     */
    public static function isValid(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (! str_starts_with($url, self::SCHEME)) {
            return false;
        }

        if (str_contains($url, ' ') || str_contains($url, '\\')) {
            return false;
        }

        // Exactly one scheme separator — the one in self::SCHEME itself.
        return substr_count($url, '://') === 1;
    }
}
