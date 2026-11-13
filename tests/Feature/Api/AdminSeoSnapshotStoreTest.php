<?php

use App\Models\GscCoverageState;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\Snapshot\SeoSnapshotStore;

/**
 * The stored SEO snapshot and seo/summary (kit 0.26.0, 2026-11-11): GET
 * seo/snapshot answers from the copy seo:snapshot-store built (every 15
 * minutes and after each Search Console sync), `computed_at` saying when;
 * `?fresh=1` builds it again; seo/summary hands ss.systems' platform
 * dashboard its health score and trouble counts without building the
 * snapshot.
 */
uses(RefreshDatabase::class);

const SEO_STORE_HUB_SCREEN = ['trend_days' => 60, 'top_days' => 28, 'clarity_days' => 7, 'rank_days' => 7];

beforeEach(function () {
    config([
        'services.admin_api.token' => ADMIN_API_TEST_TOKEN,
        'services.google.search_console_property' => null,
    ]);
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

function seoStoreProblemPage(string $path): void
{
    GscCoverageState::create([
        'url' => 'https://hive.contractors'.$path, 'source' => 'sitemap', 'verdict' => 'FAIL',
        'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => now(),
    ]);
}

function seoStoreFakeSearchConsole(): void
{
    test()->mock(SearchConsoleSyncClient::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('siteUrl')->andReturn('sc-domain:hive.contractors');
        $mock->shouldReceive('querySearchAnalytics')->andReturn([]);
        $mock->shouldReceive('getLastError')->andReturnNull();
    });
}

it('serves the stored snapshot until fresh builds it again', function () {
    $url = '/api/admin/v1/seo/snapshot?'.http_build_query(SEO_STORE_HUB_SCREEN);

    Carbon::setTestNow('2026-11-11 10:00:00');
    $first = $this->getJson($url, adminApiHeaders())->assertOk()->json('data');
    expect($first['computed_at'])->toBe('2026-11-11T10:00:00+00:00')
        ->and($first['gsc_errors']['totals']['problem'])->toBe(0);

    seoStoreProblemPage('/pricing');
    Carbon::setTestNow('2026-11-11 10:05:00');

    $stored = $this->getJson($url, adminApiHeaders())->assertOk()->json('data');
    expect($stored['computed_at'])->toBe('2026-11-11T10:00:00+00:00')
        ->and($stored['gsc_errors']['totals']['problem'])->toBe(0);

    $fresh = $this->getJson($url.'&fresh=1', adminApiHeaders())->assertOk()->json('data');
    expect($fresh['computed_at'])->toBe('2026-11-11T10:05:00+00:00')
        ->and($fresh['gsc_errors']['totals']['problem'])->toBe(1);

    expect($this->getJson($url, adminApiHeaders())->json('data'))->toBe($fresh);
});

it('builds a window the hub does not open with on every request', function () {
    $this->getJson('/api/admin/v1/seo/snapshot?trend_days=90', adminApiHeaders())->assertOk();
    seoStoreProblemPage('/pricing');

    $data = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=90', adminApiHeaders())->assertOk()->json('data');

    expect($data['gsc_errors']['totals']['problem'])->toBe(1)
        ->and($data)->toHaveKey('computed_at')
        ->and(app(SeoSnapshotStore::class)->get(['trend_days' => 90]))->toBeNull();
});

it('stores both combinations the hub asks for with seo:snapshot-store, and serves them', function () {
    Carbon::setTestNow('2026-11-11 10:15:00');

    // Artisan::call, not $this->artisan(): the harness's mocked output would
    // also swallow the `seo:health --json` the health block runs inside it.
    expect(Artisan::call('seo:snapshot-store'))->toBe(0);

    $store = app(SeoSnapshotStore::class);
    expect($store->get(SEO_STORE_HUB_SCREEN)['computed_at'])->toBe('2026-11-11T10:15:00+00:00')
        ->and($store->get([])['computed_at'])->toBe('2026-11-11T10:15:00+00:00')
        ->and($store->get(SEO_STORE_HUB_SCREEN)['health'])->toHaveKeys(['score', 'prior_score', 'prior_as_of', 'pillars']);

    seoStoreProblemPage('/pricing');
    $served = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data');

    expect($served['computed_at'])->toBe('2026-11-11T10:15:00+00:00')
        ->and($served['gsc_errors']['totals']['problem'])->toBe(0);
});

it('keeps the blocks own caches on a scheduled run and forgets them with --fresh', function () {
    $forgotten = [];
    Event::listen(KeyForgotten::class, function (KeyForgotten $event) use (&$forgotten) {
        $forgotten[] = $event->key;
    });

    expect(Artisan::call('seo:snapshot-store'))->toBe(0)
        ->and($forgotten)->not->toContain('admin.seo-reports.health-snapshot')
        ->and($forgotten)->not->toContain('admin.seo-reports.pulse');

    expect(Artisan::call('seo:snapshot-store', ['--fresh' => true]))->toBe(0)
        ->and($forgotten)->toContain('admin.seo-reports.health-snapshot')
        ->and($forgotten)->toContain('admin.seo-reports.pulse');
});

it('says what it stored', function () {
    $this->artisan('seo:snapshot-store')
        ->expectsOutputToContain('Stored the SEO snapshot for trend_days=60&top_days=28&clarity_days=7&rank_days=7 in')
        ->expectsOutputToContain('Stored the SEO snapshot for no query in')
        ->assertSuccessful();
});

it('is scheduled every fifteen minutes on production', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'seo:snapshot-store'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->environments)->toBe(['production']);
});

it('stores the snapshot when a Search Console sync finishes', function () {
    seoStoreFakeSearchConsole();

    $this->artisan('seo:gsc-sync')->assertSuccessful();

    expect(app(SeoSnapshotStore::class)->get(SEO_STORE_HUB_SCREEN))->not->toBeNull()
        ->and(app(SeoSnapshotStore::class)->get([]))->not->toBeNull();
});

it('leaves the stored snapshot alone on a dry-run sync', function () {
    seoStoreFakeSearchConsole();

    $this->artisan('seo:gsc-sync --dry-run')->assertSuccessful();

    expect(app(SeoSnapshotStore::class)->get([]))->toBeNull();
});

it('answers the dashboard card from seo/summary without building the snapshot', function () {
    seoStoreProblemPage('/pricing');
    GscCoverageState::create([
        'url' => 'https://hive.contractors/', 'source' => 'sitemap', 'verdict' => 'PASS',
        'coverage_state' => 'Submitted and indexed', 'inspected_at' => now(),
    ]);

    $data = $this->getJson('/api/admin/v1/seo/summary?prior_days=28', adminApiHeaders())->assertOk()->json('data');

    expect(array_keys($data))->toBe(['health', 'gsc_errors', 'computed_at', 'status'])
        ->and($data['gsc_errors'])->toBe(['available' => true, 'totals' => ['tracked' => 2, 'problem' => 1, 'pass' => 1, 'not_indexed' => 1]])
        ->and($data['health'])->toHaveKeys(['score', 'prior_score', 'prior_as_of', 'pillars'])
        ->and(app(SeoSnapshotStore::class)->get([]))->toBeNull();
});

it('reads the summary health score from the stored snapshot and the prior for the window asked', function () {
    Carbon::setTestNow('2026-11-11 09:00:00');
    Storage::disk('local')->put('reports/health-history.json', json_encode(['2026-11-03' => 40, '2026-10-13' => 31]));
    $pillars = [
        ['name' => 'On-page', 'score' => 80, 'color' => 'emerald'],
        ['name' => 'Freshness', 'score' => 70, 'color' => 'amber'],
        ['name' => 'Links', 'score' => 60, 'color' => 'amber'],
    ];
    app(SeoSnapshotStore::class)->put(SEO_STORE_HUB_SCREEN, ['health' => ['score' => 70, 'prior_score' => 40, 'prior_as_of' => '2026-11-03', 'pillars' => $pillars]]);

    Carbon::setTestNow('2026-11-11 09:10:00');
    $week = $this->getJson('/api/admin/v1/seo/summary', adminApiHeaders())->assertOk()->json('data');
    $month = $this->getJson('/api/admin/v1/seo/summary?prior_days=28', adminApiHeaders())->assertOk()->json('data');

    expect($week['health'])->toBe(['score' => 70, 'prior_score' => 40, 'prior_as_of' => '2026-11-03', 'pillars' => $pillars])
        ->and($month['health'])->toBe(['score' => 70, 'prior_score' => 31, 'prior_as_of' => '2026-10-13', 'pillars' => $pillars])
        ->and($month['computed_at'])->toBe('2026-11-11T09:00:00+00:00');
});

it('refuses an unauthenticated summary request', function () {
    $this->getJson('/api/admin/v1/seo/summary')->assertUnauthorized();
});
