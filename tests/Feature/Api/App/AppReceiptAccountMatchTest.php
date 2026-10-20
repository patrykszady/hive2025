<?php

use App\Models\Distribution;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Queue::fake();
});

/** @return array{vendor: Vendor, user: User} */
function appVendorMatchTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/**
 * A retail/shared vendor only resolves through Vendor::query() (including
 * implicit route-model binding) once it is in $company's own vendor tree —
 * VendorScope, a global scope on every Vendor query, limits results to it.
 * withoutGlobalScopes() sidesteps the same circular-scope read
 * appCategoriesRetailVendor() documents in AppCategoriesTest.php (reading
 * the scope requires auth()->user()->vendor->vendors, which is itself a
 * scoped Vendor query).
 */
function appVendorMatchAttach(Vendor $company, Vendor $merchant): void
{
    Vendor::withoutGlobalScopes()->find($company->id)?->vendors()->syncWithoutDetaching([$merchant->id]);
}

it('needs a token to list vendor match vendors', function () {
    $this->getJson('/api/app/v1/vendor-match')->assertUnauthorized();
});

it('refuses a Member, like TransactionBulkMatchPolicy on the web', function () {
    ['user' => $member] = appVendorMatchTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/vendor-match')->assertForbidden();
});

it('lists a retail vendor once it has a bulk match rule for this company', function () {
    ['vendor' => $company, 'user' => $admin] = appVendorMatchTeamUser(role: 1);

    $merchant = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'Menards']);
    appVendorMatchAttach($company, $merchant);
    // ReceiptAccountsIndex::vendors() only lists a vendor that has a
    // receipt_account OR receipts — a bulk match rule alone (as the web's
    // own query requires) is not enough.
    ReceiptAccount::create(['vendor_id' => $merchant->id, 'belongs_to_vendor_id' => $company->id]);
    TransactionBulkMatch::create([
        'amount' => null, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $company->id,
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendor-match')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($merchant->id)
        ->and(collect($response->json('data'))->firstWhere('id', $merchant->id)['bulk_match_count'])->toBe(1);
});

it('does not delete another company\'s matching rule when saving with none kept (security finding #10)', function () {
    ['vendor' => $companyA, 'user' => $adminA] = appVendorMatchTeamUser(role: 1);
    $companyB = Vendor::factory()->create();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    appVendorMatchAttach($companyA, $merchant);

    $foreignRule = TransactionBulkMatch::create([
        'amount' => null, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $companyB->id,
    ]);

    Sanctum::actingAs($adminA, ['app']);

    $this->putJson("/api/app/v1/vendor-match/{$merchant->id}", ['bulk_matches' => []])->assertOk();

    expect(TransactionBulkMatch::query()->whereKey($foreignRule->id)->exists())->toBeTrue();
});

it('does not let a tampered id rewrite another company\'s matching rule', function () {
    ['vendor' => $companyA, 'user' => $adminA] = appVendorMatchTeamUser(role: 1);
    $companyB = Vendor::factory()->create();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    appVendorMatchAttach($companyA, $merchant);

    $foreignRule = TransactionBulkMatch::create([
        'amount' => 42, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $companyB->id,
    ]);

    Sanctum::actingAs($adminA, ['app']);

    $this->putJson("/api/app/v1/vendor-match/{$merchant->id}", [
        'bulk_matches' => [[
            'id' => $foreignRule->id,
            'amount' => 999,
            'options' => ['amount_type' => 'ANY', 'desc' => null],
        ]],
    ])->assertOk();

    expect($foreignRule->fresh()->amount)->toEqual(42.0)
        ->and($foreignRule->fresh()->belongs_to_vendor_id)->toBe($companyB->id)
        ->and(TransactionBulkMatch::where('belongs_to_vendor_id', $companyA->id)->where('amount', 999)->exists())->toBeTrue();
});

it('updates the signed-in company\'s own matching rule normally', function () {
    ['vendor' => $company, 'user' => $admin] = appVendorMatchTeamUser(role: 1);
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    appVendorMatchAttach($company, $merchant);

    $ownRule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $company->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->putJson("/api/app/v1/vendor-match/{$merchant->id}", [
        'bulk_matches' => [[
            'id' => $ownRule->id,
            'amount' => 55,
            'options' => ['amount_type' => 'ANY', 'desc' => null],
        ]],
    ])->assertOk();

    expect($ownRule->fresh()->amount)->toEqual(55.0);
});

it('never sends a password/encrypted credential value back, only non-secret ones', function () {
    ['vendor' => $company, 'user' => $admin] = appVendorMatchTeamUser(role: 1);
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    appVendorMatchAttach($company, $merchant);

    Receipt::create([
        'vendor_id' => $merchant->id,
        'options' => ['credential_fields' => [
            ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'encrypted' => true],
        ]],
    ]);

    Sanctum::actingAs($admin, ['app']);

    // Save a username and password first.
    $this->putJson("/api/app/v1/vendor-match/{$merchant->id}", [
        'credential_values' => ['username' => 'menards_user', 'password' => 'super-secret'],
        'bulk_matches' => [],
    ])->assertOk();

    $response = $this->getJson("/api/app/v1/vendor-match/{$merchant->id}")->assertOk();

    expect($response->json('data.credential_values.username'))->toBe('menards_user')
        ->and($response->json('data.credential_values.password'))->toBe('');
});

it('lists distributions for the picker', function () {
    ['vendor' => $company, 'user' => $admin] = appVendorMatchTeamUser(role: 1);
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    appVendorMatchAttach($company, $merchant);
    Distribution::create(['name' => 'Kitchen Remodel', 'vendor_id' => $company->id]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson("/api/app/v1/vendor-match/{$merchant->id}")->assertOk();

    expect(collect($response->json('data.distributions'))->pluck('name'))->toContain('Kitchen Remodel');
});
