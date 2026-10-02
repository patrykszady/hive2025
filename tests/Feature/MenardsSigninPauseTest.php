<?php

use App\Models\Vendor;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Every automatic sign-in shows Imperva another security check, and on
 * 2026-09-29 five deploys meant five walls in an afternoon. A plain ensure
 * (the deploy's) never signs in, an uncleared check pauses automatic
 * sign-ins for 12 hours, and a person's Retry sign-in skips the pause.
 */

// ensure treats a batch folder written in the last day as proof the session
// works, and reads storage_path() for it: a developer machine holding the
// latest prod batches took that branch and skipped the code under test.
beforeEach(function () {
    $this->app->useStoragePath(sys_get_temp_dir().'/menards-pause-test-'.getmypid());
});
function pausedBrowserWithTempFile(): MenardsRemoteBrowserService
{
    $path = tempnam(sys_get_temp_dir(), 'menards-pause-');
    @unlink($path);

    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('signInPausePath')->andReturn($path);

    return $browser;
}

function pauseTestCredentials(): void
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    DB::table('receipt_accounts')->insert([
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => json_encode(['email' => 'patryk@example.test', 'password' => Crypt::encryptString('secret')]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $overrides */
function upBrowserMock($mock, array $overrides = []): void
{
    $mock->shouldReceive('checkRequirements')->andReturn(['ok' => true, 'missing' => []]);
    $mock->shouldReceive('writeExtensionDefaults')->andReturnNull();
    $mock->shouldReceive('updatesOverHttps')->andReturn(true);
    $mock->shouldReceive('status')->andReturn(array_merge([
        'running' => true, 'chrome' => true, 'extension' => true, 'configured' => true,
        'signed_in' => false, 'posts_to' => '', 'page' => 'Sign In at Menards® - Google Chrome',
    ], $overrides));
}

it('pauses automatic sign-ins after an uncleared security check, for 12 hours', function () {
    $browser = pausedBrowserWithTempFile();

    (fn () => $this->flagNeedsSignin('challenge'))->call($browser);

    expect($browser->automaticSignInPausedSince())->not->toBeNull();

    $this->travel(13)->hours();

    expect($browser->automaticSignInPausedSince())->toBeNull();
});

it('lifts the pause the moment a sign-in lands', function () {
    $browser = pausedBrowserWithTempFile();
    (fn () => $this->flagNeedsSignin('challenge'))->call($browser);

    (fn () => $this->markSignedIn())->call($browser);

    expect($browser->automaticSignInPausedSince())->toBeNull()
        ->and(file_exists($browser->signInPausePath()))->toBeFalse();
});

it('does not pause for a browser that is merely down', function () {
    $browser = pausedBrowserWithTempFile();

    (fn () => $this->flagNeedsSignin('down'))->call($browser);

    expect($browser->automaticSignInPausedSince())->toBeNull();
});

it('keeps the browser up on a plain ensure without signing in', function () {
    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock);
        $mock->shouldReceive('login')->never();
    });

    $this->artisan('menards:browser', ['action' => 'ensure'])
        ->expectsOutputToContain('Not signing in: a plain ensure only keeps the browser alive')
        ->assertSuccessful();
});

it('skips the daily sign-in while paused and puts the alert back', function () {
    pauseTestCredentials();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock);
        $mock->shouldReceive('automaticSignInPausedSince')->andReturn(now()->subHour());
        $mock->shouldReceive('flagPausedSignIn')->once();
        $mock->shouldReceive('login')->never();
    });

    $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
        ->expectsOutputToContain('is waiting for a person')
        ->assertSuccessful();
});

it('signs in on Retry even while paused', function () {
    pauseTestCredentials();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock, ['signed_in' => true, 'page' => 'Account Overview at Menards® - Google Chrome']);
        $mock->shouldReceive('automaticSignInPausedSince')->never();
        // A person asked: a real sign-in, not a guess from the page title.
        $mock->shouldReceive('login')->once()->with('patryk@example.test', 'secret', true)->andReturn(['ok' => true, 'url' => 'Account Overview at Menards® - Google Chrome']);
    });

    $this->artisan('menards:browser', ['action' => 'ensure', '--manual' => true])
        ->expectsOutputToContain('Signed in')
        ->assertSuccessful();
});

it('lifts the pause when the extension reports a working session', function () {
    $browser = pausedBrowserWithTempFile();
    (fn () => $this->flagNeedsSignin('challenge'))->call($browser);
    app()->instance(MenardsRemoteBrowserService::class, $browser);
    config(['services.menards.bridge_token' => 'bridge-test-token']);

    $this->withToken('bridge-test-token')
        ->postJson('/api/menards/sync-status', ['ok' => true, 'receipts' => 3])
        ->assertSuccessful();

    expect($browser->automaticSignInPausedSince())->toBeNull()
        ->and(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse();
});

/**
 * 2026-10-02: a receipt batch at 01:01, the 13:00 sync found the session
 * expired, and the sign-in that followed cleared that report before it met
 * the wall. From then on ensure saw only the batch, said "the session works"
 * and left the browser alone, so Retry sign-in did nothing for hours.
 */
function recentReceiptBatch(): void
{
    @mkdir(storage_path('files/_menards_ingest/20261002_010114_TEST'), 0777, true);
}

it('signs in on Retry even when a receipt batch arrived within the last day', function () {
    pauseTestCredentials();
    recentReceiptBatch();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock, ['signed_in' => true, 'page' => 'Account Overview at Menards® - Google Chrome']);
        $mock->shouldReceive('login')->once()->with('patryk@example.test', 'secret', true)->andReturn(['ok' => true, 'url' => 'Account Overview at Menards® - Google Chrome']);
    });

    $this->artisan('menards:browser', ['action' => 'ensure', '--manual' => true])
        ->expectsOutputToContain('Signed in')
        ->assertSuccessful();
});

it('does not trust a recent batch while a failed sign-in is outstanding', function () {
    pauseTestCredentials();
    recentReceiptBatch();
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->subHours(13)->toIso8601String()], 600);

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock, ['signed_in' => true, 'page' => 'Account Overview at Menards® - Google Chrome']);
        $mock->shouldReceive('automaticSignInPausedSince')->andReturnNull();
        $mock->shouldReceive('login')->once()->andReturn(['ok' => true, 'url' => 'Account Overview at Menards® - Google Chrome']);
    });

    $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
        ->doesntExpectOutputToContain('the session works; not touching the browser')
        ->assertSuccessful();
});

it('still leaves a proven session alone on the daily pass', function () {
    pauseTestCredentials();
    recentReceiptBatch();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        upBrowserMock($mock, ['signed_in' => true, 'page' => 'Account Overview at Menards® - Google Chrome']);
        $mock->shouldReceive('login')->never();
    });

    $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
        ->expectsOutputToContain('the session works; not touching the browser')
        ->assertSuccessful();
});
