<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\PageSpeed\Contracts\PageSpeedCredentials;

/**
 * PageSpeed Insights credentials — admin-writable (platform_settings),
 * config('services.pagespeed.api_key', PAGESPEED_API_KEY) kept only as a
 * transition fallback. App\Http\Controllers\Api\Admin\V1\PlatformsController
 * reads usingOwnKey() for the PageSpeed card's "configured" state (PSI
 * itself never requires a key, so that state is a soft quality signal, not
 * a hard connected/disconnected one). The kit's seo:psi-sync
 * (ss-systems/platform-kit's PageSpeedSyncCommand) is different: it
 * hard-gates on isConfigured() and stops cleanly with no key at all — see
 * Contracts\PageSpeedCredentials's own docblock for why. isConfigured() and
 * usingOwnKey() happen to mean the same thing here
 * (filled($this->apiKey())); they stay two methods because the interface
 * requires one name and this site's own existing callers expect the other.
 */
class PsiSettings implements PageSpeedCredentials
{
    public const SETTING_API_KEY = 'seo.pagespeed.api_key';

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

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
