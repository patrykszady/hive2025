<?php

use App\Models\ReceiptAccount;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * id 1 is the platform admin (AppServiceProvider's `platform-admin` gate)
 * and already exists as seed data baked into the migrations — reused rather
 * than inserted a second time (a second row with id 1 would violate
 * SQLite's unique constraint). The fresh test database's copy of that row
 * has no vendor set, so one is attached here since amazon_auth_response
 * reads it. amazon_login/amazon_auth_response control the tokens for one
 * shared, platform-wide Amazon receipt account (ReceiptAccount vendor_id
 * 54), so — like the Menards browser — they are restricted to the platform
 * admin rather than any tenant's Admin. No UI links to either route.
 */
function sec5AmazonPlatformAdmin(): User
{
    $vendor = Vendor::factory()->create();

    $user = User::query()->findOrFail(1);
    $user->forceFill([
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ])->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user->fresh();
}

function sec5AmazonTenantAdmin(): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Tenant',
        'last_name' => 'Admin',
        'email' => 'amazon-tenant-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('requires auth and the platform-admin gate for both amazon oauth routes', function () {
    $this->get('/receipts/amazon_login')->assertRedirect(route('login'));
    $this->get('/receipts/amazon_auth_response')->assertRedirect(route('login'));

    $this->actingAs(sec5AmazonTenantAdmin())
        ->get('/receipts/amazon_login')
        ->assertForbidden();

    $this->actingAs(sec5AmazonTenantAdmin())
        ->get('/receipts/amazon_auth_response')
        ->assertForbidden();
});

it('puts a random per-session nonce in the state param instead of the old fixed state=100', function () {
    $admin = sec5AmazonPlatformAdmin();

    $response = $this->actingAs($admin)->get('/receipts/amazon_login');

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['state'] ?? null)->not->toBe('100')
        ->and(strlen((string) ($query['state'] ?? '')))->toBeGreaterThanOrEqual(20);

    $response->assertSessionHas('amazon_oauth_state', $query['state']);
});

it('rejects an amazon auth-response with a mismatched state and never touches the receipt account', function () {
    $admin = sec5AmazonPlatformAdmin();

    $receiptAccount = ReceiptAccount::withoutGlobalScopes()->create([
        'vendor_id' => 54,
        'belongs_to_vendor_id' => $admin->vendor->id,
        'options' => ['untouched' => true],
    ]);

    $this->actingAs($admin)
        ->withSession(['amazon_oauth_state' => 'the-real-nonce'])
        ->get('/receipts/amazon_auth_response?state=attacker-guessed-state&code=attacker-code')
        ->assertRedirect(route('company_emails.index'));

    expect($receiptAccount->fresh()->options)->toBe(['untouched' => true]);
});

it('rejects an amazon auth-response with no state at all', function () {
    $admin = sec5AmazonPlatformAdmin();

    $receiptAccount = ReceiptAccount::withoutGlobalScopes()->create([
        'vendor_id' => 54,
        'belongs_to_vendor_id' => $admin->vendor->id,
        'options' => ['untouched' => true],
    ]);

    $this->actingAs($admin)
        ->withSession(['amazon_oauth_state' => 'the-real-nonce'])
        ->get('/receipts/amazon_auth_response?code=attacker-code')
        ->assertRedirect(route('company_emails.index'));

    expect($receiptAccount->fresh()->options)->toBe(['untouched' => true]);
});
