<?php

use App\Console\Commands\SeoRankCheck;
use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * "Where you rank" (2026-09-27, updated 2026-11-01): the live DataForSEO
 * check used to run on a schedule (seo:rank-check) with the SEO snapshot
 * reading its saved SeoSyncRun result directly. Since the kit's SEO tools
 * were wired in (SeoSnapshotController's docblock), the snapshot's
 * `rankings` key is ss-systems/platform-kit's own
 * SsSystems\Platform\Seo\Rankings\RankingsSnapshot, reading the
 * `seo_rank_snapshots` table seo:track-rankings fills — SeoRankCheck's
 * saved result no longer reaches this screen at all (it still feeds
 * EloquentHealthDataReader::latestRankSnapshots() for the health score, so
 * the command and its tests below stay in place).
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

function connectDataForSeo(): void
{
    PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'a-login');
    PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'a-password');
}

it('never calls DataForSEO while answering the SEO snapshot, and ignores SeoRankCheck\'s saved result', function () {
    connectDataForSeo();
    Http::fake(['api.dataforseo.com/*' => Http::response(['tasks' => []]), '*' => Http::response([], 200)]);

    // The old mechanism's saved result is still there (SeoRankCheck is kept
    // in place for the health score) — it must not leak into this screen.
    SeoSyncRun::record(SeoRankCheck::SYNC_KEY, [
        'current' => ['tracked' => 3, 'top3' => 1, 'top10' => 2, 'top20' => 2, 'below20' => 1],
        'checked_at' => now()->subHours(3)->toIso8601String(),
    ]);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataforseo.com'));
    // Connected, nothing in seo_rank_snapshots yet: no panel data rather
    // than a made-up zero, or SeoRankCheck's unrelated saved result.
    expect($data['rankings']['available'])->toBeFalse();
});

it('serves the kit tracker\'s seo_rank_snapshots rows to the snapshot', function () {
    connectDataForSeo();
    DB::table('seo_rank_snapshots')->insert([
        'engine' => 'google',
        'query' => 'construction management software',
        'location' => 'Chicago,Illinois,United States',
        'gsc_position' => 2,
        'result_count' => 10,
        'top_results' => '[]',
        'meta' => '[]',
        'fetched_at' => now()->subHours(3),
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data');

    expect($data['rankings']['available'])->toBeTrue()
        ->and($data['rankings']['live_serp']['current']['top3'])->toBe(1)
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
        ->and($saved['last_error'])->toBeNull()
        // Each search's own position, for the health score's rankings measure.
        ->and($saved['current']['queries'][0])->toBe(['query' => App\Services\DataForSeoService::TRACKED_QUERIES[0], 'position' => 2]);

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
