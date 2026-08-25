<?php

use App\Livewire\Expenses\ExpenseShow;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function expenseShowUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Expense',
        'last_name' => 'Show',
        'email' => 'expense-show-'.uniqid().'@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    // role_id 1 = Admin
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function expenseWithPrimaryAndSupplement(User $user): array
{
    $expense = Expense::query()->create([
        'amount' => 27.50,
        'date' => '2026-08-19',
        'vendor_id' => $user->vendor->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $primaryItems = [
        'items' => [
            ['Price' => 10.48, 'Quantity' => 1, 'TotalPrice' => 10.48, 'Description' => 'PW WT 6', 'VendorCode' => '070798005853', 'product_url' => 'https://products.example.test/dap-plastic-wood'],
        ],
        'subtotal' => 24.94,
        'total' => 27.5,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => [],
    ];
    $primary = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => $primaryItems,
    ]);

    $supplementItems = ExpenseReceipts::toSupplementReceiptItems([
        'items' => [],
        'total' => 27.5,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => ['912'],
    ], $primary->id);
    $supplement = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-scan.pdf',
        'receipt_html' => 'garbled scan text',
        'receipt_items' => $supplementItems,
    ]);

    return [$expense, $primary, $supplement];
}

it('folds a notes-only supplement scan into the primary receipt instead of a second tab', function () {
    $user = expenseShowUser();
    [$expense] = expenseWithPrimaryAndSupplement($user);

    // A resolved product page outranks the vendor's SKU search — store-only
    // codes make the search dead-end, the product link never does.
    $vendor = $user->vendor;
    $vendor->options = ['sku_search_url' => 'https://www.homedepot.com/s/'];
    $vendor->save();

    Livewire::actingAs($user)
        ->test(ExpenseShow::class, ['expense' => $expense])
        ->assertDontSee('Scan 2')
        ->assertDontSee('Receipt 2')
        ->assertSee('PW WT 6')
        ->assertSee('https://products.example.test/dap-plastic-wood')
        ->assertDontSee('homedepot.com/s/070798005853')
        ->assertSee($expense->id . '-email.pdf')
        ->assertSee($expense->id . '-scan.pdf')
        ->assertSee('View Scan')
        // The note surfaces only via the page's notes summary — no extra
        // "Handwritten note:" line inside the receipt card.
        ->assertDontSee('Handwritten note:')
        ->assertSee('912');
});

it('keeps tabs for genuinely different receipts and attaches the supplement icon to its primary tab', function () {
    $user = expenseShowUser();
    [$expense, $primary] = expenseWithPrimaryAndSupplement($user);

    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-other.pdf',
        'receipt_html' => 'a different purchase',
        'receipt_items' => [
            'items' => [
                ['Price' => 54.13, 'Quantity' => 1, 'TotalPrice' => 54.13, 'Description' => 'OTHER ITEM', 'VendorCode' => '111'],
            ],
            'subtotal' => 54.13,
            'total' => 58.55,
            'transaction_date' => '2026-08-20',
            'handwritten_notes' => [],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ExpenseShow::class, ['expense' => $expense])
        ->assertSeeInOrder(['Receipt 1', 'Receipt 2'])
        ->assertDontSee('Receipt 3')
        ->assertDontSee('Scan 2')
        ->assertSee($expense->id . '-scan.pdf')
        ->assertSee('912');
});
