<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The "Match As" box is free text the user tidies before saving, but the
 * expenses behind the row were keyed by the receipt's raw merchant name.
 * Looking them up by the edited text threw `Undefined array key "ART OF
 * VISION"` in production (2026-09-02) and saved nothing.
 */
it('saves a new retail vendor for the row even when "match as" was edited', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'Fixture Holding Co', 'business_type' => 'Sub']);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'User',
        'email' => 'match-desc@example.test', 'cell_phone' => '5551234568',
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $this->actingAs($user);

    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -120.00, 'date' => '2026-09-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'aov.pdf',
        'receipt_html' => '<div>Receipt</div>',
        'receipt_items' => ['items' => [], 'total' => '120.00', 'merchant_name' => 'ART OF VISION LLC'],
    ]);

    $component = new MatchVendor();

    expect(collect($component->expenseCards())->keys()->all())->toBe(['ART OF VISION LLC']);

    // The user shortens the merchant name before saving — the key no longer matches.
    $component->match_expense_merchant_names = [0 => ['match_desc' => 'ART OF VISION', 'vendor_id' => 'NEW']];

    try {
        $component->store_expense_vendors();
    } catch (\Throwable $e) {
        // Flux::toast has no Livewire request to attach to here ("dispatch()
        // on false", thrown from inside flux) — anything not from Flux is real.
        if (! str_contains(strtolower($e->getFile()), '/flux/')) {
            throw $e;
        }
    }

    // Look the vendor up through the expense: Vendor::businessName() title-cases
    // what was typed, so "ART OF VISION" is stored as "Art Of Vision".
    $vendorId = Expense::withoutGlobalScopes()->find($expense->id)->vendor_id;
    $created = Vendor::withoutGlobalScopes()->find($vendorId);

    expect($vendorId)->toBeGreaterThan(0)
        ->and($created)->not->toBeNull()
        ->and($created->business_type)->toBe('Retail')
        ->and(strtoupper($created->business_name))->toBe('ART OF VISION')
        // ...and the row is gone from the list once matched.
        ->and(collect($component->expenseCards()))->toBeEmpty();
});
