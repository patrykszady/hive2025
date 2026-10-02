<?php

use App\Http\Controllers\MenardsSyncStatusController;
use App\Livewire\AppSidebar;
use App\Livewire\Menards\MenardsBrowserViewer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function menardsAlertAdmin(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Test Vendor']);
    config(['services.menards.owner_vendor_id' => $vendor->id]);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Menards',
        'last_name' => 'Admin',
        'email' => 'menards-admin-' . uniqid() . '@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function sec5_menardsNonPlatformAdmin(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Another Vendor']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Other',
        'last_name' => 'Admin',
        'email' => 'other-admin-' . uniqid() . '@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('shows the Menards sign-in alert when an automated sign-in failed', function (): void {
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(AppSidebar::class)
        ->assertSeeInOrder(['Menards', 'Sign-in']);
});

it('shows the Menards sign-in alert when the extension reported an expired session', function (): void {
    Cache::put(MenardsSyncStatusController::CACHE_KEY, [
        'ok' => false,
        'error' => 'initialize.ajx returned HTML — the browser session has expired, sign in again.',
        'session_expired' => true,
        'at' => now()->toIso8601String(),
    ], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(AppSidebar::class)
        ->assertSee('Menards');
});

it('hides the Menards alert when the session is healthy', function (): void {
    Cache::put(MenardsSyncStatusController::CACHE_KEY, [
        'ok' => true,
        'session_expired' => false,
        'at' => now()->toIso8601String(),
    ], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(AppSidebar::class)
        ->assertDontSee('Menards');
});

it('renders the browser viewer page with the challenge callout and noVNC frame', function (): void {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(MenardsBrowserViewer::class)
        ->assertSee('Menards needs a human')
        ->assertSee('security challenge')
        ->assertSee('wire:click="retrySignin"', false)
        ->assertSee('/menards-vnc/vnc.html');
});

it('explains where the browser runs instead of framing a 404 where there is no browser stack', function (): void {
    config(['services.menards.chromium_binary' => null]);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(MenardsBrowserViewer::class)
        ->assertSee('Menards needs a human')
        ->assertSee('The browser runs on the production server')
        ->assertDontSee('wire:click="retrySignin"', false)
        ->assertDontSee('/menards-vnc/vnc.html');
});

it('gates the noVNC auth endpoint: guests 403, admins 204', function (): void {
    $this->get('/menards-vnc-auth')->assertForbidden();

    $this->actingAs(menardsAlertAdmin())
        ->get('/menards-vnc-auth')
        ->assertNoContent();
});

it('clears the needs-signin flag when the extension reports a working session', function (): void {
    config(['services.menards.bridge_token' => 'test-bridge-token']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    $this->withToken('test-bridge-token')
        ->postJson('/api/menards/sync-status', ['ok' => true, 'receipts' => 3])
        ->assertOk();

    expect(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse();
});

it('keeps the needs-signin flag when the extension reports a dead session', function (): void {
    config(['services.menards.bridge_token' => 'test-bridge-token']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    $this->withToken('test-bridge-token')
        ->postJson('/api/menards/sync-status', [
            'ok' => false,
            'error' => 'initialize.ajx returned HTML — the browser session has expired, sign in again.',
        ])
        ->assertOk();

    expect(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeTrue();
});

it('refuses another tenant\'s Admin the shared Menards browser page and its vnc-auth check', function (): void {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);

    Livewire::actingAs(sec5_menardsNonPlatformAdmin())
        ->test(MenardsBrowserViewer::class)
        ->assertForbidden();

    $this->actingAs(sec5_menardsNonPlatformAdmin())
        ->get('/menards-vnc-auth')
        ->assertForbidden();
});

it('lets a second admin of the owning company use the Menards browser', function (): void {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);
    $owner = menardsAlertAdmin();

    $colleague = new User();
    $colleague->forceFill([
        'first_name' => 'Second',
        'last_name' => 'Admin',
        'email' => 'menards-colleague-' . uniqid() . '@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $owner->primary_vendor_id,
    ]);
    $colleague->save();
    Vendor::query()->findOrFail($owner->primary_vendor_id)->users()->attach($colleague->id, ['role_id' => 1]);

    Livewire::actingAs($colleague)
        ->test(MenardsBrowserViewer::class)
        ->assertOk();

    $this->actingAs($colleague)
        ->get('/menards-vnc-auth')
        ->assertNoContent();
});

/**
 * 2026-10-02: the 08:00 sign-in met the hCaptcha, a person solved it, and
 * Menards answered the stale sign-in with its error page; the account then
 * sat signed out for hours because nobody pressed "Retry sign-in". The page
 * now resumes the sign-in itself once the wall it saw is gone.
 */
it('signs in again by itself once a person clears the wall on screen', function (): void {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);
    Illuminate\Support\Facades\Queue::fake();
    $this->mock(MenardsRemoteBrowserService::class)->shouldReceive('securityCheckShowing')->andReturn(true, true, false, false);

    $viewer = Livewire::actingAs(menardsAlertAdmin())->test(MenardsBrowserViewer::class)
        ->assertSee('once it clears, the sign-in starts again by itself');
    $viewer->call('$refresh');
    Illuminate\Support\Facades\Queue::assertNotPushed(Illuminate\Queue\CallQueuedClosure::class);

    $viewer->call('$refresh')->assertSee('The security check is cleared — signing in again.');
    $viewer->call('$refresh');

    Illuminate\Support\Facades\Queue::assertPushed(Illuminate\Queue\CallQueuedClosure::class, 1);
});

it('does not sign in by itself when it never saw the wall go away', function (string $reason, ?bool $showing): void {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => $reason, 'at' => now()->toIso8601String()], 600);
    Illuminate\Support\Facades\Queue::fake();
    $this->mock(MenardsRemoteBrowserService::class)->shouldReceive('securityCheckShowing')->andReturn($showing);

    $viewer = Livewire::actingAs(menardsAlertAdmin())->test(MenardsBrowserViewer::class);
    $viewer->call('$refresh');

    if ($reason === 'challenge' && $showing === false) {
        $viewer->assertSee('Hit “Retry sign-in” to finish signing in.');
    }
    Illuminate\Support\Facades\Queue::assertNotPushed(Illuminate\Queue\CallQueuedClosure::class);
})->with([
    'the wall was already gone when the page opened' => ['challenge', false],
    'no browser window' => ['challenge', null],
    'a rejected sign-in, not a wall' => ['login_failed', false],
]);
