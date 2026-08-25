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
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);

    Livewire::actingAs(menardsAlertAdmin())
        ->test(MenardsBrowserViewer::class)
        ->assertSee('Menards needs a human')
        ->assertSee('security challenge')
        ->assertSee('/menards-vnc/vnc.html');
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
