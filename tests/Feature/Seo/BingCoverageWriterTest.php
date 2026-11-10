<?php

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use App\Support\Seo\BingCoverageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SsSystems\Platform\Seo\Bing\BingCoverageSync;

/** Where SsSystems\Platform\Seo\Bing\BingCoverageSync's rows land on this app (kit 0.22.0, 2026-11-10). */
uses(RefreshDatabase::class);

it('finds the existing crawl stat row via whereDate rather than inserting a duplicate', function () {
    $writer = new BingCoverageWriter;
    $base = [
        'date' => '2026-11-09', 'site_url' => 'https://hive.contractors',
        'crawled_pages' => 10, 'in_index' => 100, 'in_links' => 5, 'crawl_errors' => 0,
        'blocked_by_robots' => 0, 'code_2xx' => 10, 'code_301' => 0, 'code_302' => 0,
        'code_4xx' => 0, 'code_5xx' => 0, 'other_codes' => 0, 'connection_timeouts' => 0, 'dns_failures' => 0,
    ];

    $writer->upsertCrawlStat($base);
    $writer->upsertCrawlStat(array_merge($base, ['crawled_pages' => 55, 'in_index' => 140]));

    expect(BingCrawlStat::count())->toBe(1);
    expect(BingCrawlStat::first()->crawled_pages)->toBe(55);
    expect(BingCrawlStat::first()->in_index)->toBe(140);
});

it('merges upsertUrlInfo keys onto one row: shown figures then fetch figures', function () {
    $writer = new BingCoverageWriter;
    $url = 'https://hive.contractors/en/welcome';
    $hash = BingCoverageSync::hash($url);

    $writer->upsertUrlInfo([
        'url' => $url, 'url_hash' => $hash, 'in_sitemap' => true,
        'impressions_28d' => 9, 'clicks_28d' => 1, 'last_shown_on' => '2026-11-05',
    ]);

    $writer->upsertUrlInfo([
        'url' => $url, 'url_hash' => $hash,
        'http_status' => 200, 'is_page' => true, 'document_size' => 1024,
        'anchor_count' => 12, 'child_url_count' => 3,
        'discovered_on' => '2026-10-01', 'last_crawled_at' => '2026-11-08 06:00:00',
        'inspected_at' => '2026-11-08 06:00:01', 'inspect_error' => null,
    ]);

    expect(BingUrlInfo::count())->toBe(1);
    $row = BingUrlInfo::first();

    // The shown figures from the first call survive the second call, which
    // never mentioned them.
    expect($row->impressions_28d)->toBe(9);
    expect($row->clicks_28d)->toBe(1);
    expect($row->last_shown_on->toDateString())->toBe('2026-11-05');

    // The fetch figures from the second call landed on the same row.
    expect($row->http_status)->toBe(200);
    expect($row->is_page)->toBeTrue();
    expect($row->document_size)->toBe(1024);
    expect($row->last_crawled_at->toDateTimeString())->toBe('2026-11-08 06:00:00');
});

it('urlsToInspect orders never-asked urls first, then the longest ago', function () {
    $writer = new BingCoverageWriter;

    $never = 'https://hive.contractors/en/welcome/never-asked';
    $old = 'https://hive.contractors/en/welcome/asked-long-ago';
    $recent = 'https://hive.contractors/en/welcome/asked-recently';

    BingUrlInfo::create([
        'url' => $old, 'url_hash' => BingCoverageSync::hash($old),
        'in_sitemap' => true, 'inspected_at' => '2026-10-01 00:00:00',
    ]);
    BingUrlInfo::create([
        'url' => $recent, 'url_hash' => BingCoverageSync::hash($recent),
        'in_sitemap' => true, 'inspected_at' => '2026-11-08 00:00:00',
    ]);
    // $never has no row at all yet — still "never asked", same tier as a
    // row whose inspected_at is null.

    $ordered = $writer->urlsToInspect([$recent, $old, $never], 10);

    expect($ordered)->toBe([$never, $old, $recent]);
});

it('urlsToInspect respects the limit', function () {
    $writer = new BingCoverageWriter;
    $urls = ['https://hive.contractors/a', 'https://hive.contractors/b', 'https://hive.contractors/c'];

    expect($writer->urlsToInspect($urls, 2))->toHaveCount(2);
    expect($writer->urlsToInspect($urls, 0))->toBe([]);
});

it('forgetUrlsNotIn marks rows outside the given set as out of the sitemap and counts them, never deleting', function () {
    $writer = new BingCoverageWriter;

    $kept = 'https://hive.contractors/en/welcome';
    $dropped = 'https://hive.contractors/en/welcome/old-page';

    BingUrlInfo::create(['url' => $kept, 'url_hash' => BingCoverageSync::hash($kept), 'in_sitemap' => true]);
    BingUrlInfo::create(['url' => $dropped, 'url_hash' => BingCoverageSync::hash($dropped), 'in_sitemap' => true]);

    $changed = $writer->forgetUrlsNotIn([$kept]);

    expect($changed)->toBe(1);
    expect(BingUrlInfo::count())->toBe(2, 'rows are marked, never deleted');
    expect(BingUrlInfo::where('url', $dropped)->first()->in_sitemap)->toBeFalse();
    expect(BingUrlInfo::where('url', $kept)->first()->in_sitemap)->toBeTrue();

    // A row already marked false, and still outside the set, is not
    // counted again — only rows that actually flip count towards $changed.
    expect($writer->forgetUrlsNotIn([$kept]))->toBe(0);
});
