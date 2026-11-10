<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What Bing knows of one sitemap URL (GetUrlInfo: when it found and last
 * fetched it; GetPageStats: whether it showed the URL in results in the
 * last 28 days), one row per URL keyed on url_hash. Written by
 * SsSystems\Platform\Seo\Bing\BingCoverageSync through App\Support\Seo\
 * BingCoverageWriter (kit 0.22.0, 2026-11-10) and read by the kit's
 * ServesBingCoverage trait for the hub's Bing card. A URL that left the
 * sitemap keeps its row with in_sitemap = false — never deleted.
 * Single-tenant site: no site_id column, same as BingDailyTotal/
 * BingTrafficStat above.
 */
class BingUrlInfo extends Model
{
    protected $table = 'bing_url_info';

    protected $fillable = [
        'url',
        'url_hash',
        'in_sitemap',
        'http_status',
        'is_page',
        'document_size',
        'anchor_count',
        'child_url_count',
        'discovered_on',
        'last_crawled_at',
        'impressions_28d',
        'clicks_28d',
        'last_shown_on',
        'inspected_at',
        'inspect_error',
    ];

    protected $casts = [
        'in_sitemap' => 'boolean',
        'http_status' => 'integer',
        'is_page' => 'boolean',
        'document_size' => 'integer',
        'anchor_count' => 'integer',
        'child_url_count' => 'integer',
        'discovered_on' => 'date',
        'last_crawled_at' => 'datetime',
        'impressions_28d' => 'integer',
        'clicks_28d' => 'integer',
        'last_shown_on' => 'date',
        'inspected_at' => 'datetime',
    ];
}
