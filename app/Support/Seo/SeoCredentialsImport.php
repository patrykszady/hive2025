<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;

/**
 * Copies BING_WMT_KEY (if present and nothing is stored yet) into the
 * encrypted platform_settings table. Adapted from dawnsellshomes' identical
 * class — this site has neither Clarity, PageSpeed nor DataForSEO
 * integrations, so SOURCES is `['bing']` alone.
 *
 * The one caller: POST platforms/seo-credentials/import
 * (App\Http\Controllers\Api\Admin\V1\PlatformsController::
 * importSeoCredentialsFromEnv()), ss.systems' Connect Services modal's
 * "Move here" button.
 *
 * Never logs or returns a credential VALUE — only which of three things
 * happened to the field:
 *   - imported:       nothing was stored yet, and BING_WMT_KEY was found
 *                      and written.
 *   - already stored: an admin-saved value already exists — never
 *                      overwritten by this class.
 *   - absent:         no env value to import (a blank, or whitespace-only,
 *                      env var counts as absent, same as an unset one).
 */
class SeoCredentialsImport
{
    /** Every source this import knows how to copy — the default when $sources is empty. */
    public const SOURCES = ['bing'];

    /**
     * @param  list<string>  $sources  Which of 'bing' to import; empty (the default) means all (just 'bing').
     * @param  bool  $dryRun  Report what WOULD happen without writing anything.
     * @return array{imported: list<string>, already_stored: list<string>, absent: list<string>}
     */
    public function run(array $sources = [], bool $dryRun = false): array
    {
        $sources = $sources === [] ? self::SOURCES : $sources;

        $result = ['imported' => [], 'already_stored' => [], 'absent' => []];

        foreach ($this->fields($sources) as $row) {
            // Raw stored check — deliberately NOT PlatformSettingCredential::get()
            // with a default, which would mask "nothing stored yet" behind
            // the env fallback and make the key look "already stored".
            $alreadyStored = filled(PlatformSetting::get($row['key']));
            $envValue = filled($row['env']) ? trim((string) $row['env']) : '';

            if ($alreadyStored) {
                $result['already_stored'][] = $row['label'];
            } elseif ($envValue !== '') {
                $result['imported'][] = $row['label'];
                if (! $dryRun) {
                    PlatformSetting::put($row['key'], $envValue);
                }
            } else {
                $result['absent'][] = $row['label'];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $sources
     * @return list<array{label:string,key:string,env:string|null}>
     */
    protected function fields(array $sources): array
    {
        $bySource = [
            'bing' => [
                ['label' => 'Bing API key', 'key' => BingSettings::SETTING_API_KEY, 'env' => config('services.bing_wmt.key')],
            ],
        ];

        $rows = [];

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
