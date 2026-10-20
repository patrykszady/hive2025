<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser(). $role is the vendor_user
 * pivot's role_id (1 = Admin, 2 = Member) — VendorPolicy::viewOptions
 * gates Options/Phone System/Categories on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appSettingsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

// --- OPTIONS -----------------------------------------------------------

it('needs a token to view options', function () {
    $this->getJson('/api/app/v1/options')->assertUnauthorized();
});

it('refuses a Member viewing options, like the web', function () {
    ['user' => $member] = appSettingsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/options')->assertForbidden();
});

it('shows an Admin the vendor options with defaults filled in', function () {
    ['user' => $admin] = appSettingsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/options')->assertOk();

    // The seeded test DB already carries ~500 real-looking users (see
    // test-db-has-seeded-users.md), some of which may already be admins on
    // vendor 1 — assert membership, not position.
    expect($response->json('data.business_hours_start'))->toBe('07:00')
        ->and($response->json('data.business_hours_end'))->toBe('18:00')
        ->and($response->json('data.sms_team_enabled'))->toBeTrue()
        ->and(collect($response->json('data.admin_users'))->pluck('id'))->toContain($admin->id);
});

it('saves options and reflects them back', function () {
    ['user' => $admin] = appSettingsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->putJson('/api/app/v1/options', [
        'short_name' => 'GS',
        'sms_team_enabled' => true,
        'sms_client_enabled' => false,
        'sms_vendor_enabled' => true,
        'default_contract_signers' => [$admin->id],
        'business_hours_start' => '08:00',
        'business_hours_end' => '17:00',
        'business_hours_days' => [1, 2, 3, 4, 5],
    ])->assertOk();

    expect($response->json('data.short_name'))->toBe('GS')
        ->and($response->json('data.sms_client_enabled'))->toBeFalse()
        ->and($response->json('data.business_hours_start'))->toBe('08:00')
        ->and($response->json('data.default_contract_signers'))->toBe([$admin->id]);
});

it('rejects business hours where the end is not after the start', function () {
    ['user' => $admin] = appSettingsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->putJson('/api/app/v1/options', [
        'sms_team_enabled' => true,
        'sms_client_enabled' => true,
        'sms_vendor_enabled' => true,
        'business_hours_start' => '09:00',
        'business_hours_end' => '08:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['business_hours_end']);
});

// --- PHONE SYSTEM --------------------------------------------------------

it('refuses the phone system for a company that is not vendor 1', function () {
    $vendor = Vendor::factory()->create(['id' => 999]);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    Sanctum::actingAs($admin, ['app']);

    // app.access itself refuses any primary_vendor_id !== 1 with a 403
    // before the controller's own vendor-1 check ever runs — this proves
    // the belt-and-suspenders check exists without needing a vendor-1
    // bypass, which EnsureAppUserAllowed makes impossible to construct.
    $this->getJson('/api/app/v1/phone-system')->assertForbidden();
});

it('shows an Admin the phone system defaults', function () {
    ['user' => $admin] = appSettingsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/phone-system')->assertOk();

    expect($response->json('data.vendor_call_order_enabled'))->toBeFalse()
        ->and($response->json('data.vendor_call_ring_mode'))->toBe('one_by_one')
        ->and($response->json('data.screening_enabled'))->toBeTrue()
        ->and($response->json('data.welcome_message'))->toContain('{company}');
});

it('refuses a Member saving the phone system, like the web', function () {
    ['user' => $member] = appSettingsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->putJson('/api/app/v1/phone-system', [
        'vendor_call_order_enabled' => false,
        'vendor_call_ring_mode' => 'one_by_one',
        'call_welcome_enabled' => true,
        'voicemail_enabled' => true,
        'screening_enabled' => true,
    ])->assertForbidden();
});

it('saves the call recipient order from the array the app sends', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSettingsTeamUser(role: 1);
    $second = User::factory()->create(['primary_vendor_id' => $vendor->id, 'cell_phone' => '2245551234']);
    $vendor->users()->attach($second->id, ['role_id' => 1]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->putJson('/api/app/v1/phone-system', [
        'call_recipients' => [$second->id, $admin->id],
        'vendor_call_order_enabled' => false,
        'vendor_call_recipients' => [],
        'vendor_call_ring_mode' => 'one_by_one',
        'call_welcome_enabled' => true,
        'voicemail_enabled' => true,
        'screening_enabled' => true,
    ])->assertOk();

    expect($response->json('data.call_recipients'))->toBe([$second->id, $admin->id]);
});
