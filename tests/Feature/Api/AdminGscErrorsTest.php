<?php

use App\Jobs\RunGscInspectBulkJob;
use App\Models\GscCoverageState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
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

    $stats = $response->json('stats');
    expect($stats)->toBe(['tracked' => 2, 'problem' => 1, 'pass' => 1, 'latest_inspected' => $stats['latest_inspected']]);
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

    Queue::assertPushed(RunGscInspectBulkJob::class);
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
