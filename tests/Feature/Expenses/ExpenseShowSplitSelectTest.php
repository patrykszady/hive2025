<?php

use App\Livewire\Expenses\ExpenseShow;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Expense 28924 (Climate Solutions #68297) was split by amount — $420 and
 * $27,198.69, every receipt item unticked — yet each split had a Select
 * button that highlighted nothing.
 *
 * @param  list<bool>  $firstTicks  receipt items ticked on the $200 split
 * @param  list<bool>  $secondTicks  receipt items ticked on the $100 split
 */
function splitSelect_expense(array $firstTicks, array $secondTicks): array
{
    $vendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Split', 'last_name' => 'Select',
        'email' => 'split-select-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $expense = Expense::query()->create([
        'amount' => 300.00, 'date' => '2026-10-12', 'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_items' => ['items' => [
            ['Description' => 'Casement window', 'TotalPrice' => 200.00, 'Quantity' => 1, 'Price' => 200.00],
            ['Description' => 'Picture window', 'TotalPrice' => 100.00, 'Quantity' => 1, 'Price' => 100.00],
        ], 'total' => 300.00],
    ]);

    $ticks = fn (array $checked) => array_map(fn (bool $on) => ['checkbox' => $on], $checked);
    foreach ([[200.00, $firstTicks], [100.00, $secondTicks]] as [$amount, $checked]) {
        ExpenseSplits::withoutGlobalScopes()->create([
            'expense_id' => $expense->id, 'amount' => $amount, 'receipt_items' => $ticks($checked),
            'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
        ]);
    }

    return [$user, $expense];
}

it('offers no Select on splits made by amount', function () {
    [$user, $expense] = splitSelect_expense([false, false], [false, false]);

    Livewire::actingAs($user)
        ->test(ExpenseShow::class, ['expense' => $expense])
        ->assertSee('Split Details')
        ->assertDontSeeHtml('aria-label="Toggle split selection"');
});

it('keeps Select on splits made by ticking receipt items', function () {
    [$user, $expense] = splitSelect_expense([true, false], [false, true]);

    Livewire::actingAs($user)
        ->test(ExpenseShow::class, ['expense' => $expense])
        ->assertSeeHtml('aria-label="Toggle split selection"');
});
