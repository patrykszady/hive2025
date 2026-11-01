<?php

use App\Models\PlatformSetting;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * "Where you rank" (2026-09-27, updated 2026-11-01): the live DataForSEO
 * check used to run on its own schedule (seo:rank-check) with the SEO
 * snapshot reading its saved SeoSyncRun result directly. Since the kit's
 * SEO tools were wired in (SeoSnapshotController's docblock), the
 * snapshot's `rankings` key is ss-systems/platform-kit's own
 * SsSystems\Platform\Seo\Rankings\RankingsSnapshot, reading the
 * `seo_rank_snapshots` table seo:track-rankings fills. seo:rank-check and
 * App\Services\DataForSeoService are deleted (2026-11-01) — the same
 * `seo_rank_snapshots` table now also feeds the health score's rankings
 * measure (EloquentHealthDataReader::latestRankSnapshots()).
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
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
    // Connected, nothing in seo_rank_snapshots yet: no panel data rather
    // than a made-up zero.
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
