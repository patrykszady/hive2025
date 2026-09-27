<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per marketing-site route (route_name + its non-locale params),
 * lazily created the first time App\Support\MarketingPages lists that page
 * — see the create_page_seo_overrides_table migration's docblock for why
 * this table exists at all and how ServiceController shares it.
 *
 * title/meta_title/meta_description all start null: an admin edit through
 * the Pages (or Services) screen is the only thing that ever sets them, and
 * a null column means "the page's own default applies" everywhere this is
 * read (App\Support\MarketingPages for the admin list, App\Support\PageSeo
 * for what head.blade.php actually renders).
 */
class PageSeoOverride extends Model
{
    protected $fillable = [
        'route_name',
        'route_params',
        'params_key',
        'title',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'route_params' => 'array',
    ];

    /**
     * Deterministic, index-safe stand-in for the route's params (ksorted,
     * JSON-encoded; '' when there are none) — see the migration's docblock
     * for why this exists instead of indexing route_params directly.
     *
     * @param  array<string, mixed>  $params
     */
    public static function paramsKey(array $params): string
    {
        if ($params === []) {
            return '';
        }

        ksort($params);

        return json_encode($params, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param  array<string, mixed>  $params */
    public static function findFor(string $routeName, array $params = []): ?self
    {
        return static::query()
            ->where('route_name', $routeName)
            ->where('params_key', static::paramsKey($params))
            ->first();
    }

    /**
     * The row this route's admin edits live on — created with every
     * override column null the first time this route is looked up, so an
     * unedited page still gets a stable id the moment it's listed.
     *
     * @param  array<string, mixed>  $params
     */
    public static function findOrCreateFor(string $routeName, array $params = []): self
    {
        return static::query()->firstOrCreate(
            ['route_name' => $routeName, 'params_key' => static::paramsKey($params)],
            ['route_params' => $params]
        );
    }
}
