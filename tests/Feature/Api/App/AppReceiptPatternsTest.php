<?php

use App\Models\Receipt;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Email receipt-PARSING patterns (/receipts on the web, App\Livewire\
 * Receipts\ReceiptsIndex) — Patryk (user id 1) only in the app, even for
 * reads (see App\Http\Controllers\Api\App\V1\ReceiptPatternController's
 * own docblock for why). The seeded Pest DB already carries ~500 users
 * including id 1 (see test-db-has-seeded-users.md), so this test file
 * reuses that row rather than inserting a conflicting one — same
 * convention as AppAgentsTest.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appReceiptPatternsTeamUser(int $userId): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    if ($userId === 1) {
        $user = User::query()->findOrFail(1);
        $user->forceFill(['primary_vendor_id' => $vendor->id])->save();
    } else {
        $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    }
    $vendor->users()->syncWithoutDetaching([$user->id => ['role_id' => 1]]);

    return compact('vendor', 'user');
}

/**
 * A shared/directory Retail vendor attached to vendor 1's own tree — real
 * retail vendors only resolve through a Receipt's `vendor` relation once
 * attached to `vendors()` (VendorScope, a global scope on every Vendor
 * query, limits results to the signed-in company's tree) — see
 * AppCategoriesTest's identically-named helper for the same reasoning.
 */
function appReceiptPatternsRetailVendor(array $overrides = []): Vendor
{
    $vendor = Vendor::factory()->create(array_merge(['business_type' => 'Retail'], $overrides));
    Vendor::withoutGlobalScopes()->find(1)?->vendors()->syncWithoutDetaching([$vendor->id]);

    return $vendor;
}

function appReceiptPattern(Vendor $vendor, array $overrides = []): Receipt
{
    return Receipt::create(array_merge([
        'vendor_id' => $vendor->id,
        'from_type' => 2,
        'from_address' => '@example.test',
        'from_subject' => ['Your order'],
        'receipt_type' => 1,
        'options' => ['receipt_start' => 'Total'],
    ], $overrides));
}

it('needs a token to list receipt patterns', function () {
    $this->getJson('/api/app/v1/receipt-patterns')->assertUnauthorized();
});

it('refuses anyone but user id 1, even an Admin, like AgentController', function () {
    ['user' => $admin] = appReceiptPatternsTeamUser(userId: 50);
    Sanctum::actingAs($admin, ['app']);

    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);
    $receipt = appReceiptPattern($vendor);

    $this->getJson('/api/app/v1/receipt-patterns')->assertForbidden();
    $this->getJson('/api/app/v1/receipt-patterns/vendors')->assertForbidden();
    $this->getJson("/api/app/v1/receipt-patterns/{$receipt->id}")->assertForbidden();
    $this->postJson('/api/app/v1/receipt-patterns', [
        'vendor_id' => $vendor->id, 'from_address' => 'new@example.test', 'receipt_type' => 1,
    ])->assertForbidden();
    $this->putJson("/api/app/v1/receipt-patterns/{$receipt->id}", [
        'vendor_id' => $vendor->id, 'from_address' => 'changed@example.test', 'receipt_type' => 1,
    ])->assertForbidden();
    $this->deleteJson("/api/app/v1/receipt-patterns/{$receipt->id}")->assertForbidden();

    expect(Receipt::count())->toBe(1)
        ->and(Receipt::first()->from_address)->toBe('@example.test');
});

it('lists receipt patterns, searchable by vendor/address/subject, for user id 1', function () {
    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $vendor = appReceiptPatternsRetailVendor(['business_name' => 'Home Depot']);
    $other = appReceiptPatternsRetailVendor(['business_name' => 'Menards']);
    $match = appReceiptPattern($vendor, ['from_address' => 'orders@homedepot.com']);
    $unrelated = appReceiptPattern($other, ['from_address' => 'receipts@menards.com']);

    $all = collect($this->getJson('/api/app/v1/receipt-patterns')->assertOk()->json('data'))->pluck('id');
    expect($all)->toContain($match->id, $unrelated->id);

    $searched = $this->getJson('/api/app/v1/receipt-patterns?search=homedepot')->assertOk();
    $ids = collect($searched->json('data'))->pluck('id');
    expect($ids)->toContain($match->id)->not->toContain($unrelated->id);

    expect($searched->json('data.0.vendor.business_name'))->toBe('Home Depot')
        ->and($searched->json('data.0.from_subjects'))->toBe(['Your order']);
});

it('pages past the first 25 receipt patterns', function () {
    ['vendor' => $vendor, 'user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    for ($i = 0; $i < 30; $i++) {
        appReceiptPattern($vendor, ['from_address' => "sender{$i}@example.test"]);
    }

    $page1 = $this->getJson('/api/app/v1/receipt-patterns')->assertOk();
    expect($page1->json('data'))->toHaveCount(25)
        ->and($page1->json('meta.last_page'))->toBe(2);

    $page2 = $this->getJson('/api/app/v1/receipt-patterns?page=2')->assertOk();
    expect($page2->json('data'))->toHaveCount(5);
});

it('lists every vendor, not only this company\'s own tree, for the From Vendor picker', function () {
    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $unrelatedVendor = Vendor::factory()->create(['business_name' => 'Totally Unrelated Co']);

    $names = collect($this->getJson('/api/app/v1/receipt-patterns/vendors')->assertOk()->json('data'))->pluck('business_name');
    expect($names)->toContain('Totally Unrelated Co');
});

it('creates a receipt pattern with subjects and parsing options, same as ReceiptsIndex::store()', function () {
    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);

    $response = $this->postJson('/api/app/v1/receipt-patterns', [
        'vendor_id' => $vendor->id,
        'from_address' => '@newvendor.com',
        'from_subjects' => ['Order confirmed', '', '  '],
        'receipt_type' => 2,
        'options' => ['receipt_start' => 'Subtotal', 'ocr' => '', 'ocr_type' => null],
    ])->assertCreated();

    $receipt = Receipt::findOrFail($response->json('data.id'));
    // Leading "@" infers a wildcard domain match (from_type 2), blank
    // subjects/options are dropped, same as the web.
    expect($receipt->from_type)->toBe(2)
        ->and($receipt->from_subject)->toBe(['Order confirmed'])
        ->and($receipt->receipt_type)->toBe(2)
        ->and($receipt->options)->toBe(['receipt_start' => 'Subtotal']);
});

it('updates a receipt pattern, clearing every option without crashing, same as ReceiptsIndex::store()', function () {
    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $vendor = Vendor::factory()->create(['business_type' => 'Retail']);
    $receipt = appReceiptPattern($vendor, ['from_address' => 'old@example.test', 'options' => ['receipt_start' => 'Total']]);

    $this->putJson("/api/app/v1/receipt-patterns/{$receipt->id}", [
        'vendor_id' => $vendor->id,
        'from_address' => 'updated@example.test',
        'from_subjects' => [],
        'receipt_type' => 1,
        'options' => [],
    ])->assertOk();

    $fresh = $receipt->fresh();
    expect($fresh->from_address)->toBe('updated@example.test')
        ->and($fresh->from_type)->toBe(3)
        ->and($fresh->from_subject)->toBeNull()
        ->and($fresh->options)->toBe([]);
});

it('deletes a receipt pattern, for user id 1 only', function () {
    ['vendor' => $vendor, 'user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $receipt = appReceiptPattern($vendor);

    $this->deleteJson("/api/app/v1/receipt-patterns/{$receipt->id}")->assertOk();

    expect(Receipt::count())->toBe(0);
});

it('requires a vendor, a from address, and a valid receipt type', function () {
    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $this->postJson('/api/app/v1/receipt-patterns', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vendor_id', 'from_address', 'receipt_type']);
});

it('is hidden from the Manage hub for anyone but user id 1 via AppSectionPermissions', function () {
    $permissionsClass = App\Services\Users\AppSectionPermissions::class;

    ['user' => $patryk] = appReceiptPatternsTeamUser(userId: 1);
    ['user' => $member] = appReceiptPatternsTeamUser(userId: 60);

    expect($permissionsClass::for($patryk->fresh()))->toHaveKey('receipt_patterns', true)
        ->and($permissionsClass::for($member->fresh()))->toHaveKey('receipt_patterns', false);
});
