<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Ported verbatim from dawnsellshomes.com's App\Support\OAuthState (itself
 * from jpeterson-design's).
 *
 * Session-less CSRF protection for the /admin-oauth/{provider}/callback
 * route (routes/web.php): this app's entire /admin surface is a stateless
 * proxy to ss-systems' central admin (see AdminProxyController), so there
 * is no local admin session to protect the callback with the way a normal
 * Laravel app would. PlatformsController::oauthUrl() mints a short-lived,
 * tamper-proof "state" value via make() and passes it as the OAuth 'state'
 * query param to Google/Meta; the callback route validates it with
 * verify() before ever exchanging a code.
 */
class OAuthState
{
    protected const TTL_MINUTES = 15;

    public static function make(string $provider): string
    {
        return Crypt::encryptString(json_encode([
            'provider' => $provider,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    public static function verify(?string $state, string $provider): bool
    {
        if (! $state) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        return ($payload['provider'] ?? null) === $provider
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;
    }
}
