<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;

/**
 * POST platforms/gsc/sync — ss.systems' Platforms Refresh asks for a Search
 * Console pull now (2026-09-29). It queues the detached job and answers at
 * once; Bus::fake() throughout, since a real run would call Google.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    Bus::fake();
});

it('queues a Search Console pull on the shared window and answers at once', function () {
    $this->postJson('/api/admin/v1/platforms/gsc/sync', [], adminApiHeaders())
        ->assertOk()
        ->assertJson(['data' => ['queued' => true]]);

    Bus::assertDispatched(RunArtisanCommandDetached::class, fn ($job) => $job->command === 'seo:gsc-sync'
        && $job->options === ['--days' => SearchConsoleSyncRule::DEFAULT_DAYS, '--lag-days' => SearchConsoleSyncRule::DEFAULT_LAG_DAYS]);
});

it('clamps an explicit window to sane bounds', function () {
    $this->postJson('/api/admin/v1/platforms/gsc/sync', ['days' => 500, 'lag_days' => -3], adminApiHeaders())->assertOk();

    Bus::assertDispatched(RunArtisanCommandDetached::class, fn ($job) => $job->options === ['--days' => 90, '--lag-days' => 0]);
});

it('needs the admin token', function () {
    $this->postJson('/api/admin/v1/platforms/gsc/sync')->assertUnauthorized();

    Bus::assertNotDispatched(RunArtisanCommandDetached::class);
});
