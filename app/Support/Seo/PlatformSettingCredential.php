<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;

/**
 * Ported verbatim from dawnsellshomes'/jpeterson-design's identical class.
 * The one resolver App\Support\Seo\BingSettings routes through, so the
 * admin-writable override and its 'admin' | 'env' | null source reporting
 * are computed in exactly one place. This app has only one source using it
 * — Bing — since it has no Clarity/PageSpeed/DataForSEO integrations.
 */
final class PlatformSettingCredential
{
    /** The stored value if a row exists, otherwise $default (usually a config() read). */
    public static function get(string $key, ?string $default = null): ?string
    {
        return PlatformSetting::get($key, $default);
    }

    /** True only when every key named has its own non-blank STORED row — never counts a config() fallback. */
    public static function configured(string ...$keys): bool
    {
        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! filled(PlatformSetting::get($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 'admin' when a stored row provides the value, 'env' when nothing is
     * stored but $configDefault carries one, null when neither does — lets
     * a status card say "still read from the server configuration" until
     * the value has actually been saved here.
     */
    public static function source(string $key, ?string $configDefault): ?string
    {
        if (filled(PlatformSetting::get($key))) {
            return 'admin';
        }

        return filled($configDefault) ? 'env' : null;
    }
}
