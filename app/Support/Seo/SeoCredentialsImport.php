<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;
use SsSystems\Platform\Seo\Credentials\Contracts\CredentialStore;
use SsSystems\Platform\Seo\Credentials\CredentialEnvImport;

/**
 * Copies every present env/config value for the four global SEO-source
 * credentials (Bing, Clarity, PageSpeed, DataForSEO) into the encrypted
 * platform_settings table. Adapted from dawnsellshomes'/jpeterson-design's
 * identical class.
 *
 * The one caller: POST platforms/seo-credentials/import
 * (App\Http\Controllers\Api\Admin\V1\PlatformsController::
 * importSeoCredentialsFromEnv()), ss.systems' Connect Services modal's
 * "Move here" button.
 *
 * The loop itself (never overwrite a stored value, trim env before judging
 * blank, never log/return a credential VALUE — only which of three buckets
 * a label fell into) now lives once in the kit
 * (SsSystems\Platform\Seo\Credentials\CredentialEnvImport) — every site ran
 * the identical logic, only the table of fields below ever differed. This
 * class stays local because the config KEY NAMES genuinely differ per site
 * (this site's own `services.bing_wmt.*`/`services.pagespeed.*`, unlike
 * gsc/jpeterson-design's `services.bing.*`/`services.google.pagespeed.*` —
 * see BingSettings/PsiSettings' own docblocks). Unlike gsc/jpeterson-
 * design, this site has no Clarity project-id cache to bust on import
 * (ClaritySettings::projectId() here reads storage directly every time),
 * so there is no onImported callback to wire.
 */
class SeoCredentialsImport
{
    /** Every source this import knows how to copy — the default when $sources is empty. */
    public const SOURCES = ['bing', 'clarity', 'pagespeed', 'dataforseo'];

    /**
     * @param  list<string>  $sources  Which of 'bing'/'clarity'/'pagespeed'/'dataforseo' to import; empty (the default) means all four.
     * @param  bool  $dryRun  Report what WOULD happen without writing anything.
     * @return array{imported: list<string>, already_stored: list<string>, absent: list<string>}
     */
    public function run(array $sources = [], bool $dryRun = false): array
    {
        $sources = $sources === [] ? self::SOURCES : $sources;

        return (new CredentialEnvImport($this->store()))->run($this->fields($sources), $dryRun);
    }

    /** This site's PlatformSetting table as the kit's CredentialStore seam — never falls back to config()/env(). */
    protected function store(): CredentialStore
    {
        return new class implements CredentialStore
        {
            public function get(string $key): ?string
            {
                return PlatformSetting::get($key);
            }

            public function put(string $key, string $value): void
            {
                PlatformSetting::put($key, $value);
            }
        };
    }

    /**
     * @param  list<string>  $sources
     * @return list<array{label: string, key: string, env: mixed}>
     */
    protected function fields(array $sources): array
    {
        $bySource = [
            'bing' => [
                ['label' => 'Bing API key', 'key' => BingSettings::SETTING_API_KEY, 'env' => config('services.bing_wmt.key')],
            ],
            'clarity' => [
                ['label' => 'Clarity project ID', 'key' => ClaritySettings::SETTING_PROJECT_ID, 'env' => config('services.microsoft.clarity.project_id')],
                ['label' => 'Clarity API token', 'key' => ClaritySettings::SETTING_API_TOKEN, 'env' => config('services.microsoft.clarity.api_token')],
            ],
            'pagespeed' => [
                ['label' => 'PageSpeed API key', 'key' => PsiSettings::SETTING_API_KEY, 'env' => config('services.pagespeed.api_key')],
            ],
            'dataforseo' => [
                ['label' => 'DataForSEO login', 'key' => DataForSeoSettings::SETTING_LOGIN, 'env' => config('services.dataforseo.login')],
                ['label' => 'DataForSEO password', 'key' => DataForSeoSettings::SETTING_PASSWORD, 'env' => config('services.dataforseo.password')],
            ],
        ];

        $rows = [];

        // Iterate SOURCES' own fixed order rather than $sources' order, so
        // the report is always bing/clarity/pagespeed/dataforseo no matter
        // what order the caller listed them in.
        foreach (self::SOURCES as $source) {
            if (! in_array($source, $sources, true)) {
                continue;
            }

            foreach ($bySource[$source] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
