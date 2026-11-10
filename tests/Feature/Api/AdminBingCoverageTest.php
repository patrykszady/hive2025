<?php

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;

/**
 * The hub's Bing card's endpoints (kit 0.22.0, 2026-11-10,
 * SsSystems\Platform\Seo\Bing\Http\Concerns\ServesBingCoverage over
 * App\Http\Controllers\Api\Admin\V1\BingCoverageController) — the Bing
 * counterpart to AdminGscErrorsTest.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('reads counts, per-row status words, the status filter and the q search from seeded rows', function () {
    BingCrawlStat::create([
        'date' => '2026-11-09', 'site_url' => 'https://hive.contractors',
        'crawled_pages' => 40, 'in_index' => 120, 'in_links' => 30, 'crawl_errors' => 1,
        'blocked_by_robots' => 0, 'code_2xx' => 39, 'code_301' => 0, 'code_302' => 0,
        'code_4xx' => 1, 'code_5xx' => 0, 'other_codes' => 0, 'connection_timeouts' => 0, 'dns_failures' => 0,
    ]);

    // shown: impressions in the last 28 days.
    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome', 'url_hash' => sha1('https://hive.contractors/en/welcome'),
        'in_sitemap' => true, 'http_status' => 200, 'is_page' => true,
        'last_crawled_at' => '2026-11-08 10:00:00', 'inspected_at' => '2026-11-08 10:00:00',
        'impressions_28d' => 12, 'clicks_28d' => 2, 'last_shown_on' => '2026-11-07',
    ]);
    // crawled: fetched by Bing, but not shown.
    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome/pricing', 'url_hash' => sha1('https://hive.contractors/en/welcome/pricing'),
        'in_sitemap' => true, 'http_status' => 200, 'is_page' => true,
        'last_crawled_at' => '2026-11-08 10:00:00', 'inspected_at' => '2026-11-08 10:00:00',
        'impressions_28d' => 0, 'clicks_28d' => 0,
    ]);
    // never_crawled: asked, Bing has no fetch on record.
    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome/finances', 'url_hash' => sha1('https://hive.contractors/en/welcome/finances'),
        'in_sitemap' => true, 'inspected_at' => '2026-11-08 10:00:00',
    ]);
    // pending: not asked yet.
    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome/vendors', 'url_hash' => sha1('https://hive.contractors/en/welcome/vendors'),
        'in_sitemap' => true,
    ]);
    // error: Bing's last fetch got a 4xx.
    BingUrlInfo::create([
        'url' => 'https://hive.contractors/en/welcome/gone', 'url_hash' => sha1('https://hive.contractors/en/welcome/gone'),
        'in_sitemap' => true, 'http_status' => 404, 'inspected_at' => '2026-11-08 10:00:00',
    ]);

    $data = $this->getJson('/api/admin/v1/seo/bing-coverage', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['configured'])->toBeFalse('no Bing key in the test environment');
    expect($data['latest']['in_index'])->toBe(120);

    // the five statuses partition the sitemap (kit 0.22.1): one tile per row, same precedence as its own status
    expect($data['urls'])->toMatchArray([
        'total' => 5, 'shown' => 1, 'crawled' => 1, 'never_crawled' => 1, 'pending' => 1, 'errors' => 1,
    ]);

    $byUrl = collect($data['rows'])->keyBy('url');
    expect($byUrl['https://hive.contractors/en/welcome']['status'])->toBe('shown');
    expect($byUrl['https://hive.contractors/en/welcome/pricing']['status'])->toBe('crawled');
    expect($byUrl['https://hive.contractors/en/welcome/finances']['status'])->toBe('never_crawled');
    expect($byUrl['https://hive.contractors/en/welcome/vendors']['status'])->toBe('pending');
    expect($byUrl['https://hive.contractors/en/welcome/gone']['status'])->toBe('error');

    $shown = $this->getJson('/api/admin/v1/seo/bing-coverage?status=shown', adminApiHeaders())
        ->assertOk()->json('data.rows');
    expect(collect($shown)->pluck('url')->all())->toBe(['https://hive.contractors/en/welcome']);

    $errors = $this->getJson('/api/admin/v1/seo/bing-coverage?status=error', adminApiHeaders())
        ->assertOk()->json('data.rows');
    expect(collect($errors)->pluck('url')->all())->toBe(['https://hive.contractors/en/welcome/gone']);

    $search = $this->getJson('/api/admin/v1/seo/bing-coverage?q=finances', adminApiHeaders())
        ->assertOk()->json('data.rows');
    expect(collect($search)->pluck('url')->all())->toBe(['https://hive.contractors/en/welcome/finances']);
});

it('refuses without the admin token, like the other seo routes', function () {
    $this->getJson('/api/admin/v1/seo/bing-coverage')->assertUnauthorized();
    $this->postJson('/api/admin/v1/seo/bing-coverage/refresh')->assertUnauthorized();
});

it('queues seo:bing-coverage on refresh and answers 202', function () {
    Queue::fake();

    $this->postJson('/api/admin/v1/seo/bing-coverage/refresh', [], adminApiHeaders())
        ->assertStatus(202)
        ->assertJsonPath('data.queued', true);

    Queue::assertPushed(RunArtisanCommandDetached::class, fn ($job) => $job->command === 'seo:bing-coverage');
});
