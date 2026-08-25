<?php

use App\Livewire\Expenses\ExpenseSplitsCreate;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function splitsExpense(float $amount, array $receiptItems): Expense
{
    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => $amount,
        'date' => '2026-08-19',
        'vendor_id' => 0,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 1,
        'paid_by' => null,
    ]);

    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-receipt.pdf',
        'receipt_html' => 'receipt text',
        'receipt_items' => $receiptItems,
    ]);

    return $expense;
}

function homeDepotItems(): array
{
    return [
        'items' => [
            ['Price' => 10.48, 'Quantity' => 1, 'TotalPrice' => 10.48, 'Description' => 'PW WT 6 DAP PLASTIC WOOD', 'VendorCode' => '070798005853'],
            ['Price' => 6.58, 'Quantity' => 1, 'TotalPrice' => 6.58, 'Description' => 'WHT MOULDING LATTICE', 'VendorCode' => '044021850480'],
            ['Price' => 1.97, 'Quantity' => 4, 'TotalPrice' => 7.88, 'Description' => '49 CRWN PRMD CROWN', 'VendorCode' => '773204104996'],
        ],
        'subtotal' => 24.94,
        'total' => 27.5,
        'total_tax' => 2.56,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => [],
    ];
}

function splitAmounts($component): array
{
    return collect($component->get('expense_splits'))
        ->map(fn ($split) => $split['amount'])
        ->values()
        ->all();
}

it('closes to a zero balance when each line item goes to its own split', function () {
    $expense = splitsExpense(27.50, homeDepotItems());

    $component = Livewire::test(ExpenseSplitsCreate::class, ['projects' => collect(), 'distributions' => collect()])
        ->call('addSplits', $expense->id)
        ->call('addSplit')
        ->set('expense_splits.0.items.0.checkbox', true)
        ->set('expense_splits.1.items.1.checkbox', true)
        ->set('expense_splits.2.items.2.checkbox', true);

    $amounts = splitAmounts($component);

    // Naive proportional rounding gives 11.56 + 7.26 + 8.69 = 27.51; the
    // penny lands on the largest split so the balance reads exactly $0.00.
    expect($amounts)->toBe([11.55, 7.26, 8.69])
        ->and(round(27.50 - array_sum($amounts), 2))->toBe(0.0);
});

it('closes to a zero balance when items are grouped across two splits', function () {
    $expense = splitsExpense(27.50, homeDepotItems());

    $component = Livewire::test(ExpenseSplitsCreate::class, ['projects' => collect(), 'distributions' => collect()])
        ->call('addSplits', $expense->id)
        ->set('expense_splits.0.items.0.checkbox', true)
        ->set('expense_splits.1.items.1.checkbox', true)
        ->set('expense_splits.1.items.2.checkbox', true);

    $amounts = splitAmounts($component);

    expect($amounts)->toBe([11.56, 15.94])
        ->and(round(27.50 - array_sum($amounts), 2))->toBe(0.0);
});

it('shows the unallocated share as the remainder while items are still unassigned', function () {
    $expense = splitsExpense(27.50, homeDepotItems());

    $component = Livewire::test(ExpenseSplitsCreate::class, ['projects' => collect(), 'distributions' => collect()])
        ->call('addSplits', $expense->id)
        ->set('expense_splits.0.items.0.checkbox', true);

    $amounts = splitAmounts($component);

    // No reconciliation yet — item 1 and 2 are unassigned, so the balance
    // honestly shows what's left, and no penny is forced anywhere.
    expect($amounts)->toBe([11.56, 0.0])
        ->and(round(27.50 - array_sum($amounts), 2))->toBe(15.94);
});

it('allocates from the expense amount even when the receipt has no printed tax or subtotal', function () {
    $expense = splitsExpense(33.00, [
        'items' => [
            ['Price' => 10.00, 'Quantity' => 1, 'TotalPrice' => 10.00, 'Description' => 'ITEM A', 'VendorCode' => 'A1'],
            ['Price' => 20.00, 'Quantity' => 1, 'TotalPrice' => 20.00, 'Description' => 'ITEM B', 'VendorCode' => 'B1'],
        ],
        'subtotal' => null,
        'total_tax' => null,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => [],
    ]);

    $component = Livewire::test(ExpenseSplitsCreate::class, ['projects' => collect(), 'distributions' => collect()])
        ->call('addSplits', $expense->id)
        ->set('expense_splits.0.items.0.checkbox', true)
        ->set('expense_splits.1.items.1.checkbox', true);

    $amounts = splitAmounts($component);

    expect($amounts)->toBe([11.0, 22.0])
        ->and(round(33.00 - array_sum($amounts), 2))->toBe(0.0);
});

it('closes to a zero balance on a negative refund expense', function () {
    $expense = splitsExpense(-78.13, [
        'items' => [
            ['Price' => -50.00, 'Quantity' => 1, 'TotalPrice' => -50.00, 'Description' => 'RETURN A', 'VendorCode' => 'A1'],
            ['Price' => -21.04, 'Quantity' => 1, 'TotalPrice' => -21.04, 'Description' => 'RETURN B', 'VendorCode' => 'B1'],
        ],
        'subtotal' => -71.04,
        'total_tax' => -7.09,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => [],
    ]);

    $component = Livewire::test(ExpenseSplitsCreate::class, ['projects' => collect(), 'distributions' => collect()])
        ->call('addSplits', $expense->id)
        ->set('expense_splits.0.items.0.checkbox', true)
        ->set('expense_splits.1.items.1.checkbox', true);

    $amounts = splitAmounts($component);

    expect(round(-78.13 - array_sum($amounts), 2))->toBe(0.0);
});
