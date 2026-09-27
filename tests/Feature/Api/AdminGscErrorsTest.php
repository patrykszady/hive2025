<?php

use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use App\Models\GscCoverageState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('lists tracked coverage rows with stats, scoped to problems by default', function () {
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/welcome',
        'source' => 'sitemap',
        'verdict' => 'PASS',
        'coverage_state' => 'Submitted and indexed',
        'inspected_at' => now(),
        'consecutive_failures' => 0,
    ]);
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/broken',
        'source' => 'sitemap',
        'verdict' => 'FAIL',
        'coverage_state' => 'Not indexed',
        'inspected_at' => now(),
        'consecutive_failures' => 2,
    ]);

    $response = $this->getJson('/api/admin/v1/seo/gsc-errors', adminApiHeaders())->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['url'])->toBe('https://hive.contractors/en/broken');
    expect($rows[0]['issue'])->toBe('Not indexed');

    // Kit 0.13.0 (ServesGscErrors) extends this app's 4-key stats() shape to
    // gsc's/jpeterson's 7-key one additively — the three new keys read off
    // this app's own MarketingSitemap-driven sitemapUrlSet(), not a static
    // file, but the math is the same.
    $stats = $response->json('stats');
    expect(array_keys($stats))->toBe(['tracked', 'problem', 'pass', 'retired', 'latest_inspected', 'sitemap_urls', 'inspection_coverage_pct']);
    expect($stats['tracked'])->toBe(2);
    expect($stats['problem'])->toBe(1);
    expect($stats['pass'])->toBe(1);
});

it('lists everything when scope=all', function () {
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/welcome',
        'source' => 'sitemap',
        'verdict' => 'PASS',
        'coverage_state' => 'Submitted and indexed',
        'inspected_at' => now(),
        'consecutive_failures' => 0,
    ]);

    $rows = $this->getJson('/api/admin/v1/seo/gsc-errors?scope=all', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1);
});

it('queues the inspection sweep on refresh without touching the queue synchronously', function () {
    Queue::fake();

    $this->postJson('/api/admin/v1/seo/gsc-errors/refresh', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.message', 'Queued full sitemap inspection in background. Data will update as the job writes new results.');

    Queue::assertPushed(RunArtisanCommandDetached::class, fn ($job) => $job->command === 'seo:gsc-inspect-bulk'
        && $job->options === ['--limit' => 0, '--markdown' => true]);
});

it('prunes coverage rows for URLs no longer in the marketing sitemap', function () {
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/this-page-was-removed-long-ago',
        'source' => 'sitemap',
        'verdict' => 'PASS',
        'coverage_state' => 'Submitted and indexed',
        'inspected_at' => now(),
        'consecutive_failures' => 0,
    ]);

    $data = $this->postJson('/api/admin/v1/seo/gsc-errors/prune-retired', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['deleted'])->toBe(1);
    expect(GscCoverageState::query()->count())->toBe(0);
});

/**
 * The kit's ServesGscErrors trait makes gsc's own long-standing
 * `source='sitemap'` scoping mandatory for every adopter (Patryk,
 * 2026-09-27, CONSOLIDATION-PLAN.md §0/§6) — this app's own pruneRetired()
 * previously had no such filter at all, so this pins the fix: a
 * sitemap-sourced row absent from the marketing sitemap is deleted, but a
 * console- or tracked-sourced row (never in the sitemap to begin with)
 * survives even though it is equally absent.
 */
it('never deletes console or tracked sourced rows even when absent from the sitemap', function () {
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/from-console-export',
        'source' => 'console',
        'verdict' => 'FAIL',
        'inspected_at' => now(),
        'consecutive_failures' => 0,
    ]);
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/googlebot-404',
        'source' => 'tracked',
        'verdict' => 'FAIL',
        'inspected_at' => now(),
        'consecutive_failures' => 0,
    ]);

    $data = $this->postJson('/api/admin/v1/seo/gsc-errors/prune-retired', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['deleted'])->toBe(0);
    expect(GscCoverageState::query()->count())->toBe(2);
});

it('exports the filtered rows as csv', function () {
    GscCoverageState::create([
        'url' => 'https://hive.contractors/en/broken',
        'source' => 'sitemap',
        'verdict' => 'FAIL',
        'coverage_state' => 'Not indexed',
        'inspected_at' => now(),
        'consecutive_failures' => 1,
    ]);

    $response = $this->get('/api/admin/v1/seo/gsc-errors/export', adminApiHeaders());

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('https://hive.contractors/en/broken');
    expect($csv)->toContain('Not indexed');
});
