<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;

/**
 * This app's IndexNow key: INDEXNOW_KEY (config('services.indexnow.key'))
 * when set, else a platform_settings row generated the first time anything
 * asks for one — 32 lowercase hex characters, well inside the kit
 * contract's required 8-128 characters of [a-z0-9-]. Generating rather
 * than refusing keeps `seo:indexnow` and the key-file route (routes/web.php)
 * working right after a fresh deploy, with no manual step — this app is
 * single-tenant, so there is exactly one key/row, never a per-site one.
 */
class IndexNowSiteKey implements IndexNowKey
{
    public const SETTING_KEY = 'seo.indexnow.key';

    public function key(): ?string
    {
        $configured = trim((string) config('services.indexnow.key'));
        if ($configured !== '') {
            return $configured;
        }

        $stored = PlatformSetting::get(self::SETTING_KEY);
        if ($stored !== null && trim($stored) !== '') {
            return $stored;
        }

        $generated = bin2hex(random_bytes(16));
        PlatformSetting::put(self::SETTING_KEY, $generated);

        return $generated;
    }

    public function keyLocation(): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$this->key().'.txt';
    }
}
