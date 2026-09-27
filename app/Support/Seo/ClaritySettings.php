<?php

namespace App\Support\Seo;

/**
 * Microsoft Clarity credentials for this site — same shape as
 * App\Support\Seo\BingSettings. An encrypted platform_settings row
 * (App\Support\Seo\PlatformSettingCredential) takes precedence over config
 * ('services.microsoft.clarity.*', CLARITY_PROJECT_ID/CLARITY_API_TOKEN),
 * which stays only as a transition fallback for a project not yet moved
 * into the admin. Read by the marketing layout's Clarity tag
 * (resources/views/components/layouts/guest.blade.php, via projectId()) and
 * by App\Support\Seo\Reports\ClaritySettingsMetricsReader for the
 * clarity-health report. ss.systems' Connect Services modal reads
 * 'admin' | 'env' | null from source() exactly like it does for Bing.
 */
class ClaritySettings
{
    public const SETTING_PROJECT_ID = 'seo.clarity.project_id';

    public const SETTING_API_TOKEN = 'seo.clarity.api_token';

    public function projectId(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_PROJECT_ID, config('services.microsoft.clarity.project_id'));
    }

    public function apiToken(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_API_TOKEN, config('services.microsoft.clarity.api_token'));
    }

    /** Not a credential — stays config()-only. */
    public function baseUrl(): string
    {
        return (string) config('services.microsoft.clarity.base_url', 'https://www.clarity.ms/export-data/api/v1');
    }

    /** Both fields, since the tag needs the project id and the health report needs the API token too. */
    public function isConfigured(): bool
    {
        return filled($this->projectId()) && filled($this->apiToken());
    }

    /**
     * 'admin' when BOTH fields are stored here, 'env' when neither is
     * stored but config() supplies both, null otherwise — a partially
     * stored pair (one field saved, one still missing) is not "admin" yet
     * since isConfigured() would still be false.
     */
    public function source(): ?string
    {
        if (PlatformSettingCredential::configured(self::SETTING_PROJECT_ID, self::SETTING_API_TOKEN)) {
            return 'admin';
        }

        $envProjectId = config('services.microsoft.clarity.project_id');
        $envApiToken = config('services.microsoft.clarity.api_token');

        return (filled($envProjectId) && filled($envApiToken)) ? 'env' : null;
    }
}
