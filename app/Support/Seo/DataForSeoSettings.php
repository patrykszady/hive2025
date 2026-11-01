<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\DataForSeo\Contracts\DataForSeoCredentials;

/**
 * DataForSEO credentials — GLOBAL, not owner-facing (same design as
 * jpeterson-design's/gs.construction's identical class): this is
 * ss.systems' own metered account, covered by every tenant's plan.
 * ss.systems holds the one key and provisions it into this site's
 * encrypted platform_settings through POST platforms/dataforseo/credentials;
 * nothing on this site ever offers an owner a login/password FIELD to type
 * these into. So unlike Bing/Clarity/PSI, a stored value here always means
 * ss.systems provisioned it — source() reports that as 'platform' rather
 * than 'admin'. The config('services.dataforseo.*') pair stays only as a
 * transition fallback for a value that predates provisioning; this site
 * never grows its own DataForSEO account. Bound as the kit's
 * DataForSeoCredentials for ss-systems/platform-kit's own SEO tools
 * (seo:track-rankings, seo:keyword-research, …) — this app's own retired
 * seo:rank-check/App\Services\DataForSeoService pair (deleted 2026-11-01)
 * used to read it directly for the live "Where you rank" check; see
 * EloquentHealthDataReader::latestRankSnapshots() for what replaced it.
 */
class DataForSeoSettings implements DataForSeoCredentials
{
    public const SETTING_LOGIN = 'seo.dataforseo.login';

    public const SETTING_PASSWORD = 'seo.dataforseo.password';

    public function login(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_LOGIN, config('services.dataforseo.login'));
    }

    public function password(): ?string
    {
        return PlatformSettingCredential::get(self::SETTING_PASSWORD, config('services.dataforseo.password'));
    }

    public function isConfigured(): bool
    {
        return filled($this->login()) && filled($this->password());
    }

    /**
     * 'platform' when both fields are stored here — the only way that
     * happens is ss.systems provisioning this tenant, since no admin UI on
     * this site writes these keys. 'env' when nothing is stored but
     * config() supplies both (the pre-provisioning transition fallback),
     * null otherwise.
     */
    public function source(): ?string
    {
        if (PlatformSettingCredential::configured(self::SETTING_LOGIN, self::SETTING_PASSWORD)) {
            return 'platform';
        }

        $envLogin = config('services.dataforseo.login');
        $envPassword = config('services.dataforseo.password');

        return (filled($envLogin) && filled($envPassword)) ? 'env' : null;
    }
}
