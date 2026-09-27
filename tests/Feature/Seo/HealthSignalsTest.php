<?php

use App\Console\Commands\SeoRankCheck;
use App\Models\BingDailyTotal;
use App\Models\SeoSyncRun;
use App\Models\User;
use App\Support\Seo\Reports\EloquentHealthDataReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The SEO health score read signals this site never produces (a static
 * sitemap file, schedule log files, a Business Profile pipeline, no rank
 * snapshots), so every measure was blank and the admin showed no score
 * (2026-09-27). It now reads what the site actually has.
 */
beforeEach(function () {
    Storage::fake('local');
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('reads rankings from the saved daily check, one position per tracked search', function () {
    SeoSyncRun::record(SeoRankCheck::SYNC_KEY, ['current' => [
        'tracked' => 2, 'top3' => 0, 'top10' => 1, 'top20' => 1, 'below20' => 1,
        'queries' => [['query' => 'a', 'position' => 7], ['query' => 'b', 'position' => null]],
    ]]);

    expect(app(EloquentHealthDataReader::class)->latestRankSnapshots())->toBe([
        ['engine' => 'google', 'position' => 7.0],
        ['engine' => 'google', 'position' => null],
    ]);
});

it('has no ranking snapshots before the first check', function () {
    expect(app(EloquentHealthDataReader::class)->latestRankSnapshots())->toBe([]);
});

it('reads freshness from the pipelines this site has, with no Business Profile line', function () {
    SeoSyncRun::record('search_console', ['status' => 'ok', 'finished_at' => now()->subDays(2)->toIso8601String()]);
    BingDailyTotal::query()->create(['date' => now()->subDay()->toDateString(), 'site_url' => 'https://hive.contractors/', 'clicks' => 0, 'impressions' => 1]);

    $signals = app(EloquentHealthDataReader::class)->freshnessSignals();

    expect(array_keys($signals))->toBe(['sitemap.xml', 'search-console sync', 'bing sync'])
        ->and($signals['search-console sync']->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and($signals['bing sync'])->not->toBeNull()
        ->and($signals['sitemap.xml'])->not->toBeNull();
});

it('does not count a failed Search Console sync as fresh', function () {
    SeoSyncRun::record('search_console', ['status' => 'error', 'finished_at' => now()->toIso8601String()]);

    expect(app(EloquentHealthDataReader::class)->freshnessSignals()['search-console sync'])->toBeNull();
});

it('dates the internal-link audit from its saved report', function () {
    Storage::disk('local')->put('reports/internal-link-suggest.md', '# report');

    expect(app(EloquentHealthDataReader::class)->internalLinkAuditLastRunAt())->not->toBeNull();
});

it('sends the leads summary the platform dashboard card reads', function () {
    $before = User::query()->whereNotNull('registration')->count();
    User::factory()->create(['registration' => ['registered' => true], 'created_at' => now()->subDays(2)]);
    User::factory()->create(['registration' => null]);

    $leads = $this->getJson('/api/admin/v1/dashboard-stats', adminApiHeaders())->assertOk()->json('data.leads');

    expect($leads['total'])->toBe($before + 1)
        ->and($leads['this_week'])->toBeGreaterThanOrEqual(1)
        ->and($leads['pending'])->toBe(0)
        ->and($leads)->toHaveKey('today');
});
