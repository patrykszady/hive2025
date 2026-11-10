<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day's Bing Webmaster Tools crawl stats (GetCrawlStats): pages Bing
 * crawled, pages in its index, errors and status codes it met. Written by
 * SsSystems\Platform\Seo\Bing\BingCoverageSync through App\Support\Seo\
 * BingCoverageWriter (kit 0.22.0, 2026-11-10) and read by the kit's
 * ServesBingCoverage trait for the hub's Bing card. Single-tenant site: no
 * site_id column, same as BingDailyTotal/BingTrafficStat above.
 */
class BingCrawlStat extends Model
{
    protected $table = 'bing_crawl_stats';

    protected $fillable = [
        'date',
        'site_url',
        'crawled_pages',
        'in_index',
        'in_links',
        'crawl_errors',
        'blocked_by_robots',
        'code_2xx',
        'code_301',
        'code_302',
        'code_4xx',
        'code_5xx',
        'other_codes',
        'connection_timeouts',
        'dns_failures',
    ];

    protected $casts = [
        'date' => 'date',
        'crawled_pages' => 'integer',
        'in_index' => 'integer',
        'in_links' => 'integer',
        'crawl_errors' => 'integer',
        'blocked_by_robots' => 'integer',
        'code_2xx' => 'integer',
        'code_301' => 'integer',
        'code_302' => 'integer',
        'code_4xx' => 'integer',
        'code_5xx' => 'integer',
        'other_codes' => 'integer',
        'connection_timeouts' => 'integer',
        'dns_failures' => 'integer',
    ];
}
