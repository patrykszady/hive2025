<?php

namespace App\Support;

/**
 * The admin API's capability registry. Each routes/api-admin/{domain}.php
 * file declares the domains it serves and PingController reports the union
 * to ss-systems, whose sidebar and screens read it — so a new screen is a
 * new file, never an edit to a shared list. Route files run at boot, which
 * means this holds nothing under `route:cache`; this app's deploy never
 * caches routes (the Forge script says why, next to `optimize`).
 */
final class AdminApi
{
    /** @var array<string, string> */
    private static array $domains = [];

    public static function declare(string ...$domains): void
    {
        foreach ($domains as $domain) {
            self::$domains[$domain] = $domain;
        }
    }

    /** @return array<int, string> */
    public static function domains(): array
    {
        $domains = array_values(self::$domains);
        sort($domains);

        return $domains;
    }
}
