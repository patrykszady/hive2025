<?php

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('treats identical receipt line items as a duplicate even if other OCR fields differ', function () {
    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -71.85,
        'date' => '2025-12-02',
        'invoice' => null,
        'note' => null,
        'project_id' => null,
        'distribution_id' => null,
        'vendor_id' => 0,
        'check_id' => null,
        'reimbursment' => null,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 1,
        'paid_by' => null,
    ]);

    $items = [
        'items' => [
            [
                'Price' => 16.33,
                'Quantity' => 4,
                'TotalPrice' => 65.32,
                'Description' => 'SD9112R100 #9X1.5 HEX SCREW 100CT',
                'ProductCode' => '707392977001',
            ],
        ],
        'total' => '71.85',
        'transaction_date' => '2025-12-02',
        'merchant_name' => 'THEHOME',
    ];

    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => '25979-a.pdf',
        'receipt_html' => "<div>Receipt</div>",
        'receipt_items' => $items,
    ]);

    $itemsWithDifferentMerchant = $items;
    $itemsWithDifferentMerchant['merchant_name'] = 'MARCELA';

    expect(ExpenseReceipts::isDuplicateForExpense($expense->id, "<div>  Receipt </div>", $itemsWithDifferentMerchant))
        ->toBeTrue();
});

it('does not treat different receipt line items as a duplicate', function () {
    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -71.85,
        'date' => '2025-12-02',
        'invoice' => null,
        'note' => null,
        'project_id' => null,
        'distribution_id' => null,
        'vendor_id' => 0,
        'check_id' => null,
        'reimbursment' => null,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 1,
        'paid_by' => null,
    ]);

    $items = [
        'items' => [
            [
                'Price' => 16.33,
                'Quantity' => 4,
                'TotalPrice' => 65.32,
                'Description' => 'SD9112R100 #9X1.5 HEX SCREW 100CT',
                'ProductCode' => '707392977001',
            ],
        ],
        'total' => '71.85',
        'transaction_date' => '2025-12-02',
    ];

    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => '25979-a.pdf',
        'receipt_html' => null,
        'receipt_items' => $items,
    ]);

    $differentItems = $items;
    $differentItems['items'][0]['Quantity'] = 3;

    expect(ExpenseReceipts::isDuplicateForExpense($expense->id, null, $differentItems))
        ->toBeFalse();
});
