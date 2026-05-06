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

uses(RefreshDatabase::class);

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
    $nylasMock->shouldReceive('downloadAttachment')->andReturn('%PDF-fake-bytes');
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

    // Multi-page Order has two receipt rows attached to the same expense
    expect(ExpenseReceipts::where('expense_id', $exp428->id)->count())->toBe(2);
    // Standalone Order has one
    expect(ExpenseReceipts::where('expense_id', $exp492->id)->count())->toBe(1);

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
    expect(ExpenseReceipts::where('expense_id', $exp->id)->count())->toBe(2);
    expect(Storage::disk('files')->files('auto_receipts_failed'))->toBeEmpty();
});
