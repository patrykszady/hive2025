<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google Business Profile listing this app's own single gbp.*
 * setting points at — written by POST platforms/gbp/listing, which
 * ss-systems' PlatformsSettings::syncSiteListing() calls to keep this in
 * step with the listing it manages (App\Models\GbpListing on ss-systems —
 * see its CLAUDE.md's "Google Business Profile listings live HERE"
 * section). Hive has no markets/areas concept, so — like gs.construction
 * and dawnsellshomes.com — there is exactly one listing, never a per-market
 * set (see App\Support\SiteMarkets on ss-systems: a site that doesn't
 * declare a 'markets' capability gets the single 'default' entry).
 *
 * Trimmed port of jpeterson-design's identical class: no publishing
 * "enabled" toggle and no Local Post/media support — this app never posts
 * to Google or uploads photos itself (it has no project photos at all; see
 * App\Services\GoogleBusinessProfileService's docblock). This class exists
 * only so PlatformsController::gbpStatus() can report
 * account_id_configured/location_id_configured/listing_source honestly.
 */
class GoogleBusinessListing
{
    public const SETTING_ACCOUNT_ID = 'gbp.account_id';

    public const SETTING_LOCATION_ID = 'gbp.location_id';

    /** Config path the stored values overlay. */
    public const CONFIG_PATH = 'services.google.business_profile';

    /** Overlay the stored listing onto config — once per request, from boot. */
    public static function apply(): void
    {
        if (! static::hasTable()) {
            return;
        }

        $accountId = PlatformSetting::get(self::SETTING_ACCOUNT_ID);
        $locationId = PlatformSetting::get(self::SETTING_LOCATION_ID);

        if ($accountId) {
            config([self::CONFIG_PATH.'.account_id' => $accountId]);
        }

        if ($locationId) {
            config([self::CONFIG_PATH.'.location_id' => $locationId]);
        }
    }

    /**
     * Save the chosen listing. Ids are stored bare ("123"), never as the
     * "accounts/123" / "locations/456" resource names Google returns.
     */
    public static function link(string $accountId, string $locationId): void
    {
        PlatformSetting::put(self::SETTING_ACCOUNT_ID, static::bareId($accountId));
        PlatformSetting::put(self::SETTING_LOCATION_ID, static::bareId($locationId));
    }

    /** "accounts/123" and "accounts/123/locations/456" both reduce to the last segment. */
    public static function bareId(string $value): string
    {
        $parts = array_values(array_filter(explode('/', trim($value))));

        return $parts === [] ? '' : (string) end($parts);
    }

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
