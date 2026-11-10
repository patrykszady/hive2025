<?php

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageClient;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageUrls;

/**
 * seo:bing-coverage (kit 0.22.0, 2026-11-10) wired on this site. A fake
 * BingCoverageClient (and, for a deterministic url set, a fake
 * BingCoverageUrls) bound into the container stand in for the real Bing
 * Webmaster Tools HTTP calls and this site's own ~260-URL marketing
 * sitemap — the same "swap the contract for an anonymous class" pattern
 * EwccvBrowserViewerTest uses. The real App\Support\Seo\BingCoverageWriter
 * (bound in AppServiceProvider) is left in place, so this proves the rows
 * actually land in bing_crawl_stats/bing_url_info.
 */
uses(RefreshDatabase::class);

it('registers and schedules seo:bing-coverage', function () {
    expect(array_keys(Artisan::all()))->toContain('seo:bing-coverage');

    $this->artisan('schedule:list')->expectsOutputToContain('seo:bing-coverage');
});

it('writes crawl stats, shown-figures and fetch info from a fake client end to end', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');

    $shownUrl = 'https://hive.contractors/en/welcome';
    $otherUrl = 'https://hive.contractors/en/welcome/pricing';

    app()->instance(BingCoverageUrls::class, new class($shownUrl, $otherUrl) implements BingCoverageUrls
    {
        public function __construct(private string $a, private string $b) {}

        public function urls(): array
        {
            return [$this->a, $this->b];
        }
    });

    app()->instance(BingCoverageClient::class, new class($shownUrl) implements BingCoverageClient
    {
        public function __construct(private string $shownUrl) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function siteUrl(): string
        {
            return 'https://hive.contractors';
        }

        public function crawlStats(): ?array
        {
            return [[
                'date' => '2026-11-09', 'crawled_pages' => 40, 'in_index' => 120, 'in_links' => 30,
                'crawl_errors' => 1, 'blocked_by_robots' => 0, 'code_2xx' => 39, 'code_301' => 0,
                'code_302' => 0, 'code_4xx' => 1, 'code_5xx' => 0, 'other_codes' => 0,
                'connection_timeouts' => 0, 'dns_failures' => 0,
            ]];
        }

        public function urlInfo(string $url): ?array
        {
            return [
                'url' => $url, 'http_status' => 200, 'is_page' => true, 'document_size' => 2048,
                'anchor_count' => 10, 'child_url_count' => 4, 'discovered_on' => '2026-10-01',
                'last_crawled_at' => '2026-11-08 06:00:00',
            ];
        }

        public function pageStats(): ?array
        {
            return [[
                'date' => '2026-11-08', 'url' => $this->shownUrl, 'impressions' => 9, 'clicks' => 1, 'position' => 4.2,
            ]];
        }

        public function urlSubmissionQuota(): ?array
        {
            return ['daily' => 100, 'monthly' => 3000];
        }

        public function userSites(): ?array
        {
            return [['url' => 'https://hive.contractors', 'verified' => true]];
        }

        public function lastError(): ?string
        {
            return null;
        }
    });

    $this->artisan('seo:bing-coverage --limit=10')->assertExitCode(0);

    expect(BingCrawlStat::count())->toBe(1);
    $stat = BingCrawlStat::first();
    expect($stat->site_url)->toBe('https://hive.contractors');
    expect($stat->in_index)->toBe(120);
    expect($stat->crawl_errors)->toBe(1);

    expect(BingUrlInfo::count())->toBe(2);

    $shown = BingUrlInfo::where('url', $shownUrl)->first();
    expect($shown->impressions_28d)->toBe(9);
    expect($shown->clicks_28d)->toBe(1);
    expect($shown->http_status)->toBe(200);
    expect($shown->is_page)->toBeTrue();
    expect($shown->last_crawled_at)->not->toBeNull();
    expect($shown->in_sitemap)->toBeTrue();

    $other = BingUrlInfo::where('url', $otherUrl)->first();
    expect($other->impressions_28d)->toBe(0, 'not in this run\'s page stats, so it reads zero again');
    expect($other->http_status)->toBe(200);
});

it('marks a url that left the sitemap as out of it, without deleting its row', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');

    $kept = 'https://hive.contractors/en/welcome';

    app()->instance(BingCoverageUrls::class, new class($kept) implements BingCoverageUrls
    {
        public function __construct(private string $kept) {}

        public function urls(): array
        {
            return [$this->kept];
        }
    });

    app()->instance(BingCoverageClient::class, new class implements BingCoverageClient
    {
        public function isConfigured(): bool
        {
            return true;
        }

        public function siteUrl(): string
        {
            return 'https://hive.contractors';
        }

        public function crawlStats(): ?array
        {
            return [];
        }

        public function urlInfo(string $url): ?array
        {
            return null;
        }

        public function pageStats(): ?array
        {
            return [];
        }

        public function urlSubmissionQuota(): ?array
        {
            return null;
        }

        public function userSites(): ?array
        {
            return null;
        }

        public function lastError(): ?string
        {
            return null;
        }
    });

    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome/retired',
        'url_hash' => sha1('https://hive.contractors/en/welcome/retired'),
        'in_sitemap' => true,
    ]);

    $this->artisan('seo:bing-coverage --limit=0')->assertExitCode(0);

    expect(BingUrlInfo::count())->toBe(2, 'the retired row stays, the current one is created');
    expect(BingUrlInfo::where('url', 'https://hive.contractors/en/welcome/retired')->first()->in_sitemap)->toBeFalse();
    expect(BingUrlInfo::where('url', $kept)->first()->in_sitemap)->toBeTrue();
});
