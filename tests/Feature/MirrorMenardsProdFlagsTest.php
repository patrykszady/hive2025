<?php

use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * menards:mirror-prod-flags copies the two Menards cache flags from
 * production so the dev sidebar shows "Menards — Sign-in" exactly when prod
 * does. Production is only ever read.
 */
beforeEach(function () {
    Cache::flush();
});

it('copies the flags production has and clears the ones it does not', function () {
    Process::fake([
        '*' => Process::result(output: "Psy Shell notice\n".json_encode([
            MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY => ['reason' => 'challenge', 'at' => '2026-09-23T07:27:18+00:00'],
            MenardsSyncStatusController::CACHE_KEY => null,
        ])."\n"),
    ]);
    Cache::put(MenardsSyncStatusController::CACHE_KEY, ['session_expired' => true], now()->addDay());

    $this->artisan('menards:mirror-prod-flags')
        ->expectsOutputToContain('menards:needs_signin: {"reason":"challenge"')
        ->assertSuccessful();

    expect(Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))
        ->toBe(['reason' => 'challenge', 'at' => '2026-09-23T07:27:18+00:00'])
        ->and(Cache::has(MenardsSyncStatusController::CACHE_KEY))->toBeFalse();

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'hive-prod')
        && str_contains(implode(' ', (array) $process->command), 'tinker'));
});

it('leaves the cache alone when production cannot be read', function () {
    Process::fake([
        '*' => Process::result(errorOutput: 'ssh: connect to host hive-prod port 22: Connection refused', exitCode: 255),
    ]);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge'], now()->addDay());

    $this->artisan('menards:mirror-prod-flags')
        ->expectsOutputToContain('Could not read the Menards flags from production')
        ->assertFailed();

    expect(Cache::get(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBe(['reason' => 'challenge']);
});

it('never runs in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Process::fake();

    $this->artisan('menards:mirror-prod-flags')->assertFailed();

    Process::assertNothingRan();
    app()->detectEnvironment(fn () => 'testing');
});
