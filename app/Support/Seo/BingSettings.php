<?php

namespace App\Support\Seo;

/**
 * Bing Webmaster Tools credentials for this site — ported from
 * dawnsellshomes' identical class. An encrypted platform_settings row
 * (App\Support\Seo\PlatformSettingCredential) takes precedence over config
 * ('services.bing_wmt.key', BING_WMT_KEY), which stays only as a
 * transition fallback for a key that has not been moved into the admin
 * yet. ss.systems' Connect Services modal reads 'admin' | 'env' | null
 * from source() exactly like it does for the other kit sites' Bing card.
 */
class BingSettings
{
    public const SETTING_API_KEY = 'seo.bing.api_key';

    public function apiKey(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_KEY, config('services.bing_wmt.key'));
    }

    /** Not a credential — stays config()-only. */
    public function siteUrl(): string
    {
        return (string) config('services.bing_wmt.site_url');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /** 'admin' | 'env' | null — see PlatformSettingCredential::source(). */
    public function source(): ?string
    {
        return PlatformSettingCredential::source(self::SETTING_API_KEY, config('services.bing_wmt.key'));
    }
}
