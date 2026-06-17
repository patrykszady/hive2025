<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

uses(RefreshDatabase::class);

function fakePdfBytes(string $text): string
{
    $pdf = new Fpdi();
    $pdf->AddPage();
    $pdf->SetFont('Helvetica');
    $pdf->SetXY(10, 10);
    $pdf->Write(8, $text);

    return $pdf->Output('S');
}

it('treats two attachments with the same invoice_number as one expense with multiple receipt pages', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => "MUNCH'S SUPPLY",
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-1',
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-0', 'filename' => 'scan-0.pdf'],
                    ['id' => 'att-1', 'filename' => 'scan-1.pdf'],
                    ['id' => 'att-2', 'filename' => 'scan-2.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturnUsing(function (...$args) {
        static $index = 0;
        $index++;

        return fakePdfBytes('Continuation test page ' . $index);
    });
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $callIndex = 0;
    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->times(3)
        ->andReturnUsing(function () use (&$callIndex) {
            $callIndex++;

            // Attachment #0: standalone receipt for Order S9464492.002
            if ($callIndex === 1) {
                return [
                    'fields' => [
                        'invoice_number' => 'S9464492.002',
                        'merchant_name' => "MUNCH'S SUPPLY",
                        'transaction_date' => '2026-03-24',
                        'total' => 18.94,
                    ],
                    'content' => 'Order S9464492.002 page 1 of 1',
                ];
            }

            // Attachment #1: page 1 of 2 for Order S9464428.002 (totals present)
            if ($callIndex === 2) {
                return [
                    'fields' => [
                        'invoice_number' => 'S9464428.002',
                        'merchant_name' => "MUNCH'S SUPPLY",
                        'transaction_date' => '2026-03-24',
                        'total' => 38.26,
                    ],
                    'content' => 'Order S9464428.002 page 1 of 2',
                ];
            }

            // Attachment #2: page 2 of 2 for Order S9464428.002 (no totals -> error path,
            // but partial.invoice_number lets the caller dedupe and attach it.)
            return [
                'error' => true,
                'reason' => 'missing_amount',
                'partial' => [
                    'invoice_number' => 'S9464428.002',
                    'merchant_name' => "MUNCH'S SUPPLY",
                    'transaction_date' => '2026-03-24',
                ],
            ];
        });
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(2);

    $exp428 = Expense::withoutGlobalScopes()->where('invoice', 'S9464428.002')->first();
    $exp492 = Expense::withoutGlobalScopes()->where('invoice', 'S9464492.002')->first();
    expect($exp428)->not->toBeNull();
    expect($exp492)->not->toBeNull();

    // Multi-page Order is merged into a single receipt row
    expect(ExpenseReceipts::where('expense_id', $exp428->id)->count())->toBe(1);
    // Standalone Order has one
    expect(ExpenseReceipts::where('expense_id', $exp492->id)->count())->toBe(1);

    $mergedReceipt = ExpenseReceipts::where('expense_id', $exp428->id)->first();
    expect($mergedReceipt)->not->toBeNull();
    expect($mergedReceipt->receipt_items['is_multi_page'] ?? false)->toBeTrue();
    expect($mergedReceipt->receipt_items['page_files'] ?? [])->toHaveCount(2);

    // The continuation page must NOT be moved to the failed-debug folder
    expect(Storage::disk('files')->files('auto_receipts_failed'))->toBeEmpty();
});

it('still moves a truly failed (non-duplicate) attachment to the failed-debug folder', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => "MUNCH'S SUPPLY",
        'business_type' => 'Retail',
    ]);

    $companyEmail = CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-1',
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-0', 'filename' => 'scan-0.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturn('%PDF-fake-bytes');
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->once()
        ->andReturn([
            'error' => true,
            'reason' => 'missing_amount',
            'partial' => [
                'invoice_number' => 'S9999999.001',
                'merchant_name' => null,
                'transaction_date' => null,
            ],
        ]);
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
    expect(Storage::disk('files')->files('auto_receipts_failed'))->toHaveCount(1);
});

it('attaches a continuation page to the prior expense when the analyzer flags continued_from_previous (no invoice_number)', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => "MUNCH'S SUPPLY",
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-1',
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-0', 'filename' => 'page-1.pdf'],
                    ['id' => 'att-1', 'filename' => 'page-2.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturn('%PDF-fake-bytes');
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $callIndex = 0;
    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->times(2)
        ->andReturnUsing(function () use (&$callIndex) {
            $callIndex++;

            if ($callIndex === 1) {
                return [
                    'fields' => [
                        'invoice_number' => 'S9464428.002',
                        'merchant_name' => "MUNCH'S SUPPLY",
                        'transaction_date' => '2026-03-24',
                        'total' => 38.26,
                        'page_number' => 1,
                        'page_total' => 2,
                        'continued_from_previous' => false,
                    ],
                    'content' => 'Order S9464428.002 page 1 of 2',
                ];
            }

            // Continuation page where the barcode/invoice id failed to OCR.
            // Only analyzer signals (continued_from_previous + page_number > 1) link it.
            return [
                'error' => true,
                'reason' => 'missing_amount',
                'partial' => [
                    'invoice_number' => null,
                    'merchant_name' => "MUNCH'S SUPPLY",
                    'transaction_date' => '2026-03-24',
                    'page_number' => 2,
                    'page_total' => 2,
                    'continued_from_previous' => true,
                ],
            ];
        });
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(1);

    $exp = Expense::withoutGlobalScopes()->where('invoice', 'S9464428.002')->first();
    expect($exp)->not->toBeNull();
    expect(ExpenseReceipts::where('expense_id', $exp->id)->count())->toBe(1);

    $receipt = ExpenseReceipts::where('expense_id', $exp->id)->first();
    expect($receipt)->not->toBeNull();
    expect($receipt->receipt_items['is_multi_page'] ?? false)->toBeTrue();
    expect($receipt->receipt_items['page_total'] ?? null)->toBe(2);
    expect($receipt->receipt_items['page_files'] ?? [])->toHaveCount(2);
    expect(Storage::disk('files')->files('auto_receipts_failed'))->toBeEmpty();
});

it('attaches same-message adjacent detail page without invoice_number to prior expense', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => 'DSP MOTORSPORTS',
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-1',
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-0', 'filename' => 'header-page.pdf'],
                    ['id' => 'att-1', 'filename' => 'detail-page.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturnUsing(function (...$args) {
        static $index = 0;
        $index++;

        return fakePdfBytes('Detail test page ' . $index);
    });
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $callIndex = 0;
    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->times(2)
        ->andReturnUsing(function () use (&$callIndex) {
            $callIndex++;

            if ($callIndex === 1) {
                return [
                    'fields' => [
                        'invoice_number' => '106552',
                        'merchant_name' => 'DSP MOTORSPORTS',
                        'transaction_date' => '2026-05-12',
                        'total' => 178.24,
                        'items' => [
                            [
                                'Description' => 'MOUNT AND BALANCE FRONT TIRE',
                                'Price' => 163.95,
                                'Quantity' => 1,
                                'TotalPrice' => 163.95,
                                'VendorCode' => null,
                            ],
                        ],
                        'raw_content' => 'DSP MOTORSPORTS Repair Order Doc Number: 106552',
                    ],
                    'content' => 'DSP MOTORSPORTS Repair Order Doc Number: 106552',
                ];
            }

            return [
                'fields' => [
                    'invoice_number' => null,
                    'merchant_name' => 'DSP MOTORSPORTS',
                    'transaction_date' => '2026-05-22',
                    'total' => 178.24,
                    'items' => [
                        [
                            'Description' => 'VALVE STEM TR412 STD',
                            'Price' => 4.95,
                            'Quantity' => 1,
                            'TotalPrice' => 4.95,
                            'VendorCode' => '0360-0003EA',
                        ],
                        [
                            'Description' => 'MOUNT AND BALANCE FRONT TIRE',
                            'Price' => 159.00,
                            'Quantity' => 1,
                            'TotalPrice' => 159.00,
                            'VendorCode' => null,
                        ],
                    ],
                    'raw_content' => '# Detail Description: MOUNT AND BALANCE FRONT TIRE Resolve Concern',
                ],
                'content' => '# Detail Description: MOUNT AND BALANCE FRONT TIRE Resolve Concern',
            ];
        });
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(1);

    $expense = Expense::withoutGlobalScopes()->first();
    expect($expense)->not->toBeNull();
    expect(ExpenseReceipts::where('expense_id', $expense->id)->count())->toBe(1);

    $receipt = ExpenseReceipts::where('expense_id', $expense->id)->first();
    expect($receipt)->not->toBeNull();
    expect((string) $receipt->auto_receipt_message_id)->toBe('msg-1');
    expect((int) $receipt->auto_receipt_attachment_index)->toBe(1);
    expect($receipt->receipt_items['is_multi_page'] ?? false)->toBeTrue();
    expect($receipt->receipt_items['attachment_indexes'] ?? [])->toBe([1, 2]);
    expect($receipt->receipt_items['page_files'] ?? [])->toHaveCount(2);
    expect($receipt->receipt_items['items'] ?? [])->toHaveCount(2);

    $lineDescriptions = collect($receipt->receipt_items['items'] ?? [])->pluck('Description')->all();
    expect($lineDescriptions)->toBe([
        'VALVE STEM TR412 STD',
        'MOUNT AND BALANCE FRONT TIRE',
    ]);

    $mergedPdf = new Fpdi();
    $pageCount = $mergedPdf->setSourceFile(Storage::disk('files')->path('receipts/' . $receipt->receipt_filename));
    expect($pageCount)->toBe(2);
});

it('dedups exact-duplicate attachments delivered in separate Epson emails but lists the receipt in both batches', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => "MUNCH'S SUPPLY",
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-A',
                'date' => 1716412044,
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-A0', 'filename' => 'scan.pdf'],
                ],
            ],
            [
                'id' => 'msg-B',
                'date' => 1716412144,
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-B0', 'filename' => 'scan.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturn(fakePdfBytes('Same content'));
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    // Both OCR calls return identical content/fields → second one must be detected
    // as a duplicate and skipped (no new ExpenseReceipts row), but a batch_item
    // must still link the second batch to the original receipt.
    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')->andReturn([
        'fields' => [
            'invoice_number' => 'S0000001.001',
            'merchant_name' => "MUNCH'S SUPPLY",
            'transaction_date' => '2026-03-24',
            'total' => 42.00,
        ],
        'content' => 'Same content for both emails',
    ]);
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    // Exactly one expense and one underlying receipt row
    expect(Expense::withoutGlobalScopes()->count())->toBe(1);
    expect(ExpenseReceipts::count())->toBe(1);

    // Two batches were processed, each one carrying a batch_item that points
    // at the same receipt id.
    $batches = \App\Models\AutoReceiptEmailBatch::orderBy('id')->get();
    expect($batches)->toHaveCount(2);

    $items = \App\Models\AutoReceiptEmailBatchItem::orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $receiptId = ExpenseReceipts::first()->id;
    expect($items->pluck('expense_receipt_id')->unique()->all())->toBe([$receiptId]);
    expect($items->pluck('batch_id')->all())->toBe($batches->pluck('id')->all());

    // Livewire batches() output: the same receipt id appears in both batches,
    // ordered newest batch first.
    $component = new \App\Livewire\Expenses\AutoReceipts();
    $rendered = $component->batches();
    expect($rendered)->toHaveCount(2);
    expect($rendered[0])->toBe([$receiptId]);
    expect($rendered[1])->toBe([$receiptId]);
});

it('stores invoice from OCR raw content when invoice_number field is missing', function () {
    Storage::fake('files');

    $vendor = Vendor::factory()->create([
        'business_name' => "MUNCH'S SUPPLY",
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [
            [
                'id' => 'msg-1',
                'from' => [['email' => 'noreply@print.epsonconnect.com']],
                'subject' => 'Receipt Scans',
                'attachments' => [
                    ['id' => 'att-0', 'filename' => 'scan-0.pdf'],
                ],
            ],
        ],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturn(fakePdfBytes('Oak Park invoice test'));
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->once()
        ->andReturn([
            'fields' => [
                'invoice_number' => null,
                'merchant_name' => "MUNCH'S SUPPLY",
                'transaction_date' => '2026-06-10',
                'total' => 100.00,
                'raw_content' => "Invoice Number:\nPRRCA202602421 - 359894,Residential Permit Deposit\n\nPayment Amount:\n$100.00",
            ],
            'content' => "Invoice Number:\nPRRCA202602421 - 359894,Residential Permit Deposit\n\nPayment Amount:\n$100.00",
        ]);
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(1);

    $expense = Expense::withoutGlobalScopes()->first();
    expect($expense)->not->toBeNull();
    expect($expense->invoice)->toBe('PRRCA202602421 - 359894');
});

it('does not merge different merchants solely by same amount and date', function () {
    Storage::fake('files');

    $lakeBluffVendor = Vendor::factory()->create([
        'business_name' => 'Village of Lake Bluff',
        'business_type' => 'Retail',
    ]);

    $redLightVendor = Vendor::factory()->create([
        'business_name' => 'Redlightviolations.com',
        'business_type' => 'Retail',
    ]);

    CompanyEmail::create([
        'vendor_id' => $lakeBluffVendor->id,
        'email' => 'patryk@gs.construction',
        'grant_id' => 'grant-test',
        'api_json' => [
            'INBOX_FOLDER' => 'inbox-fld',
            'HIVE_RECEIPTS_FOLDER' => 'hive-fld',
        ],
    ]);

    $nylasMock = Mockery::mock(NylasService::class);
    $nylasMock->shouldReceive('syncMessages')->andReturn([
        'messages' => [[
            'id' => 'msg-1',
            'from' => [['email' => 'noreply@print.epsonconnect.com']],
            'subject' => 'Receipt Scans',
            'attachments' => [
                ['id' => 'att-0', 'filename' => 'lake-bluff.pdf'],
                ['id' => 'att-1', 'filename' => 'red-light.pdf'],
            ],
        ]],
    ]);
    $nylasMock->shouldReceive('downloadAttachment')->andReturnUsing(function () {
        static $i = 0;
        $i++;

        return fakePdfBytes('merchant split test page '.$i);
    });
    $nylasMock->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylasMock);

    $callIndex = 0;
    $rcMock = Mockery::mock(ReceiptController::class)->makePartial();
    $rcMock->shouldReceive('extractReceipt')
        ->times(2)
        ->andReturnUsing(function () use (&$callIndex) {
            $callIndex++;

            if ($callIndex === 1) {
                return [
                    'fields' => [
                        'invoice_number' => null,
                        'merchant_name' => 'LAKE BLUFF VILLAGE',
                        'transaction_date' => '2026-02-12',
                        'total' => 100.00,
                        'items' => [
                            ['Description' => 'Building permit invoice'],
                        ],
                    ],
                    'content' => 'Lake Bluff payment confirmation',
                ];
            }

            return [
                'fields' => [
                    'invoice_number' => null,
                    'merchant_name' => 'GregRedLightViolations',
                    'transaction_date' => '2026-02-12',
                    'total' => 100.00,
                    'items' => [
                        ['Description' => 'Red light ticket payment'],
                    ],
                ],
                'content' => 'Red light violations payment confirmation',
            ];
        });
    app()->instance(ReceiptController::class, $rcMock);

    $controller = new CompanyEmailController($nylasMock);
    $controller->fetchAutoReceipts();

    expect(Expense::withoutGlobalScopes()->count())->toBe(2);

    $expenses = Expense::withoutGlobalScopes()->orderBy('id')->get();
    expect($expenses->pluck('vendor_id')->all())->toContain($lakeBluffVendor->id);
    expect($expenses->pluck('id')->unique()->count())->toBe(2);

    $receiptRows = ExpenseReceipts::query()->orderBy('id')->get();
    expect($receiptRows)->toHaveCount(2);
    expect($receiptRows[0]->expense_id)->not->toBe($receiptRows[1]->expense_id);
});
