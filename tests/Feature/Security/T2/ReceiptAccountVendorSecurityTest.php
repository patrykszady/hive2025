<?php

use App\Livewire\ReceiptAccounts\ReceiptAccountVendorCreate;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Outside a real Livewire request there is no "current" component for
 * modal()->close()/Flux::toast() to attach to — both are near the end of
 * store(), after everything under test has already run.
 */
function sec2r_ignoreLivewireChrome(callable $callback): void
{
    try {
        $callback();
    } catch (\Error $e) {
        if (! str_contains($e->getMessage(), 'dispatch()')) {
            throw $e;
        }
    }
}

function sec2r_admin(): array
{
    $vendor = Vendor::factory()->create();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2r-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return [$vendor, $admin];
}

// Finding 10: transactions_bulk_match has no tenant scope of its own — store()
// must never delete or rewrite another company's matching rules for a shared
// vendor.
it('does not delete another company\'s matching rule when saving with none kept', function () {
    [$companyA, $adminA] = sec2r_admin();
    [$companyB] = sec2r_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);

    $foreignRule = TransactionBulkMatch::create([
        'amount' => null, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $companyB->id,
    ]);

    test()->actingAs($adminA);
    $component = new ReceiptAccountVendorCreate();
    $component->vendor = $merchant;
    $component->transactions_bulk_matches = [];

    sec2r_ignoreLivewireChrome(fn () => $component->store());

    expect(TransactionBulkMatch::query()->whereKey($foreignRule->id)->exists())->toBeTrue();
});

it('does not let a tampered id rewrite another company\'s matching rule', function () {
    [$companyA, $adminA] = sec2r_admin();
    [$companyB] = sec2r_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);

    $foreignRule = TransactionBulkMatch::create([
        'amount' => 42, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $companyB->id,
    ]);

    test()->actingAs($adminA);
    $component = new ReceiptAccountVendorCreate();
    $component->vendor = $merchant;
    // transactions_bulk_matches is a plain public array — id is client-writable.
    $component->transactions_bulk_matches = [[
        'id' => $foreignRule->id,
        'amount' => 999,
        'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null],
        'split' => false,
        'splits' => null,
    ]];

    sec2r_ignoreLivewireChrome(fn () => $component->store());

    expect($foreignRule->fresh()->amount)->toEqual(42.0)
        ->and($foreignRule->fresh()->belongs_to_vendor_id)->toBe($companyB->id)
        ->and(TransactionBulkMatch::where('belongs_to_vendor_id', $companyA->id)->where('amount', 999)->exists())->toBeTrue();
});

it('updates the signed-in company\'s own matching rule normally', function () {
    [$company, $admin] = sec2r_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);

    $ownRule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id, 'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null], 'belongs_to_vendor_id' => $company->id,
    ]);

    test()->actingAs($admin);
    $component = new ReceiptAccountVendorCreate();
    $component->vendor = $merchant;
    $component->transactions_bulk_matches = [[
        'id' => $ownRule->id,
        'amount' => 55,
        'distribution_id' => null,
        'options' => ['amount_type' => 'ANY', 'desc' => null],
        'split' => false,
        'splits' => null,
    ]];

    sec2r_ignoreLivewireChrome(fn () => $component->store());

    expect($ownRule->fresh()->amount)->toEqual(55.0);
});
