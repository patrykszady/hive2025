<?php

namespace App\Support;

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * This app's own Google OAuth client for Business Profile sign-in — the
 * "OAuth 2.0 Client ID" made in the business's Google Cloud project.
 * Entered on the central admin's Platforms screen (client id + client
 * secret), stored encrypted in platform_settings, applied onto
 * config('services.google.business_profile') at boot.
 *
 * Trimmed port of dawnsellshomes.com's/jpeterson-design's identical class:
 * only ONE config path here. Search Console on this app runs on a
 * server-held service account (App\Support\Google\ServiceAccountToken),
 * not OAuth, so unlike jpeterson-design/gsc there is no
 * services.google.search_console overlay and redirectUris() carries only
 * 'gbp'. No JSON-upload parsing either — the admin screen only ever sends
 * client_id + client_secret (see ss-systems' PlatformsSettings::
 * saveGoogleCredentials()). GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET are the
 * env fallback for a server that has not connected through the admin yet.
 */
class GoogleOAuthApp
{
    public const SETTING_CLIENT_ID = 'google.oauth.client_id';

    public const SETTING_CLIENT_SECRET = 'google.oauth.client_secret';

    /** Config path the stored client overlays. */
    public const CONFIG_PATH = 'services.google.business_profile';

    /** Overlay the stored client onto config — once per request, from boot. */
    public static function apply(): void
    {
        if (! static::hasTable()) {
            return;
        }

        $clientId = PlatformSetting::get(self::SETTING_CLIENT_ID);
        $clientSecret = PlatformSetting::get(self::SETTING_CLIENT_SECRET);

        if (! $clientId || ! $clientSecret) {
            return;
        }

        config([
            self::CONFIG_PATH.'.client_id' => $clientId,
            self::CONFIG_PATH.'.client_secret' => $clientSecret,
        ]);
    }

    /** Store the client and make it live for the rest of this request. */
    public static function save(string $clientId, string $clientSecret): void
    {
        $previous = PlatformSetting::get(self::SETTING_CLIENT_ID);

        PlatformSetting::put(self::SETTING_CLIENT_ID, trim($clientId));
        PlatformSetting::put(self::SETTING_CLIENT_SECRET, trim($clientSecret));

        static::apply();

        if ($previous && trim($clientId) !== $previous) {
            static::forgetGrantsFromThePreviousClient();
        }
    }

    /**
     * Drop the Business Profile grant when the client underneath it
     * changes — a refresh token belongs to the OAuth client that issued
     * it, so pointing this app at a different client already dead-ends
     * the stored grant.
     */
    protected static function forgetGrantsFromThePreviousClient(): void
    {
        OAuthToken::where('provider', GoogleBusinessProfileService::PROVIDER)->delete();
        Cache::forget('google_business_profile_access_token');
    }

    /** Forget the stored client; config falls back to whatever env provides. */
    public static function clear(): void
    {
        foreach ([self::SETTING_CLIENT_ID, self::SETTING_CLIENT_SECRET] as $key) {
            PlatformSetting::put($key, null);
        }

        config([
            self::CONFIG_PATH.'.client_id' => env('GOOGLE_CLIENT_ID'),
            self::CONFIG_PATH.'.client_secret' => env('GOOGLE_CLIENT_SECRET'),
        ]);
    }

    /** Where Google sends the sign-in back to — must be registered on the client. */
    public static function redirectUris(): array
    {
        return ['gbp' => route('admin-oauth.callback', ['provider' => 'gbp'])];
    }

    /** 'admin' when the stored client is in use, 'env' when the server's env provides one, null when neither. */
    public static function source(): ?string
    {
        if (static::hasTable() && PlatformSetting::get(self::SETTING_CLIENT_ID) && PlatformSetting::get(self::SETTING_CLIENT_SECRET)) {
            return 'admin';
        }

        return (config(self::CONFIG_PATH.'.client_id') && config(self::CONFIG_PATH.'.client_secret')) ? 'env' : null;
    }

    /** Presence and provenance only — never the secret, and only a hint of the id. */
    public static function status(): array
    {
        $clientId = (string) config(self::CONFIG_PATH.'.client_id');
        $source = static::source();

        return [
            'configured' => $source !== null,
            'source' => $source,
            'client_id_hint' => $clientId !== '' ? static::hint($clientId) : null,
            // No Google Cloud project id is recorded here — this app never
            // accepted a JSON-upload form that carried one.
            'project_id' => null,
            'redirect_uris' => static::redirectUris(),
        ];
    }

    /** "1234567890-abc…apps.googleusercontent.com" — enough to recognise which client, not enough to reuse. */
    protected static function hint(string $clientId): string
    {
        if (strlen($clientId) <= 24) {
            return substr($clientId, 0, 6).'…';
        }

        return substr($clientId, 0, 14).'…'.substr($clientId, -28);
    }

    /**
     * One schema check per request: a "yes" is remembered on the app
     * container (fresh per app instance, so a test's pre-migrate boot never
     * poisons the next one); a "no" is asked again, since boot can run
     * before migrate.
     */
    protected static function hasTable(): bool
    {
        if (app()->bound('platform_settings.table')) {
            return true;
        }

        try {
            $exists = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return false;
        }

        if ($exists) {
            app()->instance('platform_settings.table', true);
        }

        return $exists;
    }
}
