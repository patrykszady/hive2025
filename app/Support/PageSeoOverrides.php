<?php

namespace App\Support;

use App\Models\PageSeoOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The write side shared by the Pages and Services admin screens: persist an
 * SEO override and forget the CachePublicPage entries it just made stale,
 * so "the admin edit shows on the site" is true on the very next guest
 * request rather than up to 60 minutes later.
 */
class PageSeoOverrides
{
    /** @param  array<string, mixed>  $fields  any of title/meta_title/meta_description, only the ones actually sent */
    public static function apply(PageSeoOverride $override, array $fields): PageSeoOverride
    {
        if ($fields !== []) {
            $override->fill($fields)->save();
        }

        self::forgetCache($override->route_name, $override->route_params ?? []);

        return $override;
    }

    /**
     * CachePublicPage keys on the guest-visible path (public-page:.md5(path),
     * matching Illuminate\Http\Request::path() — no leading slash). A
     * localized route (everything except legal.*) has one cached path PER
     * SUPPORTED LOCALE even though the override applies to all of them at
     * once, so every locale's key is forgotten here.
     *
     * @param  array<string, string>  $params
     */
    public static function forgetCache(string $routeName, array $params): void
    {
        $isLegal = Str::startsWith($routeName, 'legal.');

        $locales = $isLegal ? [null] : array_keys(config('locales.supported', ['en' => []]));

        foreach ($locales as $locale) {
            $routeParams = $locale === null ? $params : array_merge(['locale' => $locale], $params);
            $path = ltrim(route($routeName, $routeParams, false), '/');

            Cache::forget('public-page:'.md5($path));
        }
    }
}
