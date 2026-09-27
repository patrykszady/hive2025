<?php

use App\Console\Commands\SeoRankCheck;
use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * "Where you rank" (2026-09-27): the live DataForSEO check runs on a
 * schedule (seo:rank-check) and the SEO snapshot only reads its saved
 * result. Checked live inside the request it took 21.6s — past the central
 * admin's 15s wait — so the whole SEO screen failed every half hour.
 */
beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

function connectDataForSeo(): void
{
    PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'a-login');
    PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'a-password');
}

it('never calls DataForSEO while answering the SEO snapshot', function () {
    connectDataForSeo();
    Http::fake(['api.dataforseo.com/*' => Http::response(['tasks' => []]), '*' => Http::response([], 200)]);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataforseo.com'));
    // Connected, nothing checked yet: no panel data rather than a made-up zero.
    expect($data['rankings'] ?? [])->toBe([]);
});

it('serves the last saved check to the snapshot', function () {
    connectDataForSeo();
    SeoSyncRun::record(SeoRankCheck::SYNC_KEY, [
        'current' => ['tracked' => 3, 'top3' => 1, 'top10' => 2, 'top20' => 2, 'below20' => 1],
        'checked_at' => now()->subHours(3)->toIso8601String(),
    ]);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data');

    expect($data['rankings']['live_serp']['current']['top10'])->toBe(2)
        ->and($data['rankings']['as_of'])->toBe(now()->subHours(3)->toDateString());
});

it('saves a completed check and keeps the last good one when a check fails', function () {
    connectDataForSeo();
    $item = fn (string $domain, int $rank) => ['type' => 'organic', 'rank_group' => $rank, 'rank_absolute' => $rank, 'domain' => $domain, 'url' => "https://{$domain}/"];
    $ok = Http::response(['status_code' => 20000, 'tasks' => [[
        'status_code' => 20000,
        'result' => [['items' => [$item('hive.contractors', 2), $item('example.com', 1)]]],
    ]]]);
    // One answer per tracked search for the first check; every later call
    // (the second check) fails. One fake: the first registered stub wins.
    $sequence = Http::sequence();
    foreach (App\Services\DataForSeoService::TRACKED_QUERIES as $query) {
        $sequence->pushResponse($ok);
    }
    $sequence->whenEmpty(Http::response([], 500));
    Http::fake(['api.dataforseo.com/*' => $sequence]);

    $this->artisan('seo:rank-check')->assertSuccessful();
    $saved = SeoSyncRun::summary(SeoRankCheck::SYNC_KEY);
    expect($saved['current']['tracked'])->toBeGreaterThan(0)
        ->and($saved['last_error'])->toBeNull();

    $this->artisan('seo:rank-check')->assertFailed();

    $after = SeoSyncRun::summary(SeoRankCheck::SYNC_KEY);
    expect($after['current'])->toBe($saved['current'])
        ->and($after['last_error'])->not->toBeNull();
});

it('does nothing when DataForSEO is not connected', function () {
    Http::fake();

    $this->artisan('seo:rank-check')->assertSuccessful();

    Http::assertNothingSent();
    expect(SeoSyncRun::summary(SeoRankCheck::SYNC_KEY))->toBeNull();
});
