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
