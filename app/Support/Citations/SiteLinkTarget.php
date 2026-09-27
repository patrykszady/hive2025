<?php

namespace App\Support\Citations;

use SsSystems\Platform\Citations\Contracts\LinkTarget;

/**
 * hive's own "what counts as us" for the citations link check — moved
 * unchanged from `App\Console\Commands\CitationsCheckLinks` into its own
 * class so it can implement the kit's `Citations\Contracts\LinkTarget`
 * (see `SsSystems\Platform\Citations\LinkCheckRunner`, kit 0.13.0).
 *
 * Genuinely different chain from gsc/jpeterson's `config('app.url')` +
 * `brand.*` (not a bug to unify, per the 2026-09-27 audit): this app's
 * admin host and its public marketing site are two different hosts, so
 * `app.marketing_url` (falling back to `app.url`) is what a directory
 * listing would actually link to.
 */
class SiteLinkTarget implements LinkTarget
{
    public function domain(): string
    {
        return (string) parse_url((string) config('app.marketing_url', config('app.url')), PHP_URL_HOST);
    }

    public function siteUrl(): string
    {
        return rtrim((string) config('app.marketing_url', config('app.url')), '/');
    }

    /** @return list<string> */
    public function businessNames(): array
    {
        return array_filter([(string) config('app.name'), 'Hive']);
    }
}
