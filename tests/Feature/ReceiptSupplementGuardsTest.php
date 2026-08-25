<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function guardsExpense(): Expense
{
    return Expense::withoutGlobalScopes()->create([
        'amount' => -32.00,
        'date' => '2026-08-19',
        'vendor_id' => 0,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 1,
        'paid_by' => null,
    ]);
}

it('never demotes pages of the same email against each other in the multi-attachment path', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = guardsExpense();

    $pageOneFields = [
        'items' => [
            ['Price' => 10.00, 'Quantity' => 1, 'TotalPrice' => 10.00, 'Description' => 'ITEM A', 'VendorCode' => 'A1'],
            ['Price' => 20.00, 'Quantity' => 1, 'TotalPrice' => 20.00, 'Description' => 'ITEM B', 'VendorCode' => 'B1'],
        ],
        'subtotal' => 30.00,
        'total' => 32.00,
        'transaction_date' => '2026-08-19',
        'purchase_order' => '',
        'handwritten_notes' => [],
    ];
    // Same total/date and MORE reconciling items — without the same-message
    // guard this page would "supersede" page one and demote it.
    $pageTwoFields = [
        'items' => [
            ['Price' => 5.00, 'Quantity' => 1, 'TotalPrice' => 5.00, 'Description' => 'ITEM C', 'VendorCode' => 'C1'],
            ['Price' => 10.00, 'Quantity' => 1, 'TotalPrice' => 10.00, 'Description' => 'ITEM D', 'VendorCode' => 'D1'],
            ['Price' => 15.00, 'Quantity' => 1, 'TotalPrice' => 15.00, 'Description' => 'ITEM E', 'VendorCode' => 'E1'],
        ],
        'subtotal' => 30.00,
        'total' => 32.00,
        'transaction_date' => '2026-08-19',
        'purchase_order' => '',
        'handwritten_notes' => [],
    ];

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('downloadAttachment')->andReturn('%PDF-1.4 fake receipt page bytes');
    app()->instance(NylasService::class, $nylasMock);

    $rcMock = Mockery::mock(ReceiptController::class);
    $rcMock->shouldReceive('extractReceipt')->andReturn(['content' => 'page two text', 'fields' => $pageTwoFields]);
    app()->instance(ReceiptController::class, $rcMock);

    Storage::disk('files')->put('_temp_ocr/orig.pdf', '%PDF-1.4 original');

    $message = [
        'id' => 'msg-multi',
        'grant_id' => 'grant-multi',
        'attachments' => [
            ['id' => 'att-0', 'filename' => 'page-1.pdf'],
            ['id' => 'att-1', 'filename' => 'page-2.pdf'],
        ],
    ];

    (new CompanyEmailController($nylasMock))->saveExpenseReceipt(
        $expense->id,
        ['content' => 'page one text', 'fields' => $pageOneFields],
        'orig.pdf',
        $message,
    );

    $rows = ExpenseReceipts::query()->where('expense_id', $expense->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->isSupplement())->toBeFalse()
        ->and($rows[0]->receipt_items['items'])->toHaveCount(2)
        ->and($rows[1]->isSupplement())->toBeFalse()
        ->and($rows[1]->receipt_items['items'])->toHaveCount(3);
});

it('keeps a supplement a supplement when receipts:re-ocr refreshes it', function () {
    Storage::fake('files');

    $expense = guardsExpense();

    $primaryItems = [
        'items' => [
            ['Price' => 10.00, 'Quantity' => 1, 'TotalPrice' => 10.00, 'Description' => 'ITEM A', 'VendorCode' => 'A1'],
            ['Price' => 20.00, 'Quantity' => 1, 'TotalPrice' => 20.00, 'Description' => 'ITEM B', 'VendorCode' => 'B1'],
        ],
        'subtotal' => 30.00,
        'total' => 32.00,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => [],
    ];
    $primary = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean text',
        'receipt_items' => $primaryItems,
    ]);
    $supplement = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-scan.pdf',
        'receipt_html' => 'garbled text',
        'receipt_items' => ExpenseReceipts::toSupplementReceiptItems([
            'items' => [],
            'total' => 32.00,
            'transaction_date' => '2026-08-19',
            'handwritten_notes' => [],
        ], $primary->id),
    ]);

    Storage::disk('files')->put('receipts/' . $primary->receipt_filename, '%PDF-1.4 email');
    Storage::disk('files')->put('receipts/' . $supplement->receipt_filename, '%PDF-1.4 scan');

    $freshOcrFields = [
        'items' => [
            ['Price' => 10.00, 'Quantity' => 1, 'TotalPrice' => 10.00, 'Description' => 'GARBLED ITEM', 'VendorCode' => 'A1'],
        ],
        'subtotal' => 30.00,
        'total' => 32.00,
        'transaction_date' => '2026-08-19',
        'handwritten_notes' => ['912'],
    ];
    $rcMock = Mockery::mock(ReceiptController::class);
    $rcMock->shouldReceive('extractReceipt')->andReturn(['content' => 're-ocr text', 'fields' => $freshOcrFields]);
    app()->instance(ReceiptController::class, $rcMock);
    app()->instance(NylasService::class, Mockery::mock(NylasService::class));

    $this->artisan('receipts:re-ocr', ['--expense' => $expense->id])->assertSuccessful();

    $supplement->refresh();
    $primary->refresh();

    expect($supplement->isSupplement())->toBeTrue()
        ->and($supplement->receipt_items['items'])->toBe([])
        ->and($supplement->receipt_items['supplement_of_receipt_id'])->toBe($primary->id)
        ->and($supplement->receipt_items['supplanted_items'])->toHaveCount(1)
        ->and($supplement->receipt_items['handwritten_notes'])->toBe(['912'])
        ->and($primary->isSupplement())->toBeFalse()
        ->and($primary->receipt_items['items'])->toHaveCount(1);
});
