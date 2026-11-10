<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\Bing\Http\Concerns\ServesBingCoverage;

/**
 * The hub's Bing card's endpoints (GET seo/bing-coverage, POST
 * seo/bing-coverage/refresh) — what Bing's index holds of this site, kept
 * in bing_crawl_stats/bing_url_info by seo:bing-coverage (kit 0.22.0,
 * 2026-11-10). index()/refresh() and their private helpers come from the
 * kit's ServesBingCoverage trait; this controller supplies only the three
 * site-specific pieces it asks for, next to GscErrorController.
 */
class BingCoverageController extends Controller
{
    use ServesBingCoverage;

    protected function bingCrawlStatModel(): string
    {
        return BingCrawlStat::class;
    }

    protected function bingUrlInfoModel(): string
    {
        return BingUrlInfo::class;
    }

    protected function dispatchBingCoverageSync(): void
    {
        RunArtisanCommandDetached::dispatch('seo:bing-coverage');
    }
}
