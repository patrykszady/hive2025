<?php

namespace App\Support\Seo;

/**
 * PageSpeed Insights credentials — admin-writable (platform_settings),
 * config('services.pagespeed.api_key', PAGESPEED_API_KEY) kept only as a
 * transition fallback. Deliberately NO isConfigured() hard gate: PSI has
 * never required a key (it just runs on Google's shared quota without one),
 * so this only exposes usingOwnKey() — a soft quality signal, never a
 * connected/disconnected boolean. App\Services\PageSpeedInsightsService
 * reads through this class rather than config() directly. usingOwnKey() is
 * also what gates the 'psi_snapshots' report capability
 * (App\Support\Seo\Reports\ReportCapabilities) and the seo:psi-sync
 * schedule — see those classes' docblocks for why.
 */
class PsiSettings
{
    public const SETTING_API_KEY = 'seo.pagespeed.api_key';

    public function apiKey(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_KEY, config('services.pagespeed.api_key'));
    }

    /** Soft signal, not a hard gate — PSI runs keyless too, just on a lower shared quota. */
    public function usingOwnKey(): bool
    {
        return filled($this->apiKey());
    }

    /** 'admin' | 'env' | null — see PlatformSettingCredential::source(). */
    public function source(): ?string
    {
        return PlatformSettingCredential::source(self::SETTING_API_KEY, config('services.pagespeed.api_key'));
    }
}
