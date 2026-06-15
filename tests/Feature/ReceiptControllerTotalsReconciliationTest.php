<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;

/**
 * Regression: Menards receipt on expense 26964 (receipt 17201) had its
 * Subtotal label missed by Azure CU. The OCR result contained tax (10.61),
 * total (143.21) and a single line item with TotalPrice=132.60. We can
 * reconcile the missing subtotal: 143.21 - 10.61 = 132.60 == items_sum,
 * so subtotal should be filled in post-OCR.
 */
function fakeCuMissingSubtotalResult(): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Total'        => ['valueNumber' => 143.21],
                    'TotalTax'     => ['valueNumber' => 10.61],
                    // Subtotal intentionally missing.
                    'Items'        => [
                        'valueArray' => [
                            ['valueObject' => [
                                'Description' => ['valueString' => 'BROWN MULCH 2 CU. FT. BAG'],
                                'Quantity'    => ['valueNumber' => 60],
                                'Price'       => ['valueNumber' => 2.21],
                                'TotalPrice'  => ['valueNumber' => 132.60],
                                'ProductCode' => ['valueString' => '1803044'],
                            ]],
                        ],
                    ],
                ],
            ]],
            'content' => "MENARDS\nBROWN MULCH 2 CU. FT. BAG\n1803044 60@\$2.21\n\$132.60\nTaxes and Fees\n\$10.61\nTotal\n\$143.21\n",
            'styles'  => [],
        ],
    ];
}

function fakeCuMissingTaxResult(): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Total'        => ['valueNumber' => 143.21],
                    'Subtotal'     => ['valueNumber' => 132.60],
                    // TotalTax intentionally missing.
                    'Items'        => [
                        'valueArray' => [
                            ['valueObject' => [
                                'Description' => ['valueString' => 'BROWN MULCH 2 CU. FT. BAG'],
                                'Quantity'    => ['valueNumber' => 60],
                                'Price'       => ['valueNumber' => 2.21],
                                'TotalPrice'  => ['valueNumber' => 132.60],
                            ]],
                        ],
                    ],
                ],
            ]],
            'content' => "MENARDS\nBROWN MULCH\n\$132.60\nSubtotal\n\$132.60\nTotal\n\$143.21\n",
            'styles'  => [],
        ],
    ];
}

function fakeCuMissingTotalResult(): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Subtotal'     => ['valueNumber' => 132.60],
                    'TotalTax'     => ['valueNumber' => 10.61],
                    // Total intentionally missing.
                    'Items'        => [
                        'valueArray' => [
                            ['valueObject' => [
                                'Description' => ['valueString' => 'BROWN MULCH 2 CU. FT. BAG'],
                                'Quantity'    => ['valueNumber' => 60],
                                'Price'       => ['valueNumber' => 2.21],
                                'TotalPrice'  => ['valueNumber' => 132.60],
                            ]],
                        ],
                    ],
                ],
            ]],
            'content' => "MENARDS\nBROWN MULCH\n\$132.60\nSubtotal\n\$132.60\nTaxes and Fees\n\$10.61\n",
            'styles'  => [],
        ],
    ];
}

function fakeCuMismatchedItemsResult(): array
{
    // Items sum is 50.00 but total - tax = 132.60. Reconciler should NOT
    // overwrite the missing subtotal because the arithmetic disagrees.
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Total'        => ['valueNumber' => 143.21],
                    'TotalTax'     => ['valueNumber' => 10.61],
                    'Items'        => [
                        'valueArray' => [
                            ['valueObject' => [
                                'Description' => ['valueString' => 'PARTIAL ITEM LIST'],
                                'Quantity'    => ['valueNumber' => 1],
                                'TotalPrice'  => ['valueNumber' => 50.00],
                            ]],
                        ],
                    ],
                ],
            ]],
            'content' => "MENARDS\nPARTIAL ITEM\n\$50.00\nTaxes and Fees\n\$10.61\nTotal\n\$143.21\n",
            'styles'  => [],
        ],
    ];
}

/**
 * Regression: National Construction Rental "Payment Confirmation" emails are a
 * payment summary with NO itemized line items, so Azure CU returns no Items
 * array. $formattedItems stayed null and was passed to reconcileReceiptTotals()
 * (which type-hints array), throwing a TypeError that moved the email to the
 * HIVE RECEIPTS ERROR folder instead of creating the expense.
 */
function fakeCuNoLineItemsResult(): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'National Construction Rentals'],
                    'Total'        => ['valueNumber' => 1930.32],
                    'InvoiceId'    => ['valueString' => '1904466', 'confidence' => 0.95],
                    // No Items / LineItems key at all.
                ],
            ]],
            'content' => "National Construction Rentals\nPayment Number: WEBPMT0001156282\nCapture Amount:\n\$1,930.32\nPaid Invoice List\n1904466\t\$1,930.32\n",
            'styles'  => [],
        ],
    ];
}

it('extracts totals without crashing when the document has no line items', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuNoLineItemsResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 1930.32);

    $fields = $result['fields'];

    expect($result['error'] ?? null)->toBeNull()
        ->and($fields['items'])->toBeNull()
        ->and($fields['total'])->toEqualWithDelta(1930.32, 0.001)
        ->and($fields['invoice_number'])->toBe('1904466');
});

it('fills missing subtotal from line items when total - tax matches items sum', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuMissingSubtotalResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 143.21);

    $fields = $result['fields'];

    expect($fields['subtotal'])->toEqualWithDelta(132.60, 0.001)
        ->and($fields['total_tax'])->toEqualWithDelta(10.61, 0.001)
        ->and($fields['total'])->toEqualWithDelta(143.21, 0.001)
        ->and($fields['misc_fees'])->toBeNull();
});

it('fills missing tax when subtotal + total are present and items confirm subtotal', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuMissingTaxResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 143.21);

    $fields = $result['fields'];

    expect($fields['subtotal'])->toEqualWithDelta(132.60, 0.001)
        ->and($fields['total_tax'])->toEqualWithDelta(10.61, 0.001)
        ->and($fields['total'])->toEqualWithDelta(143.21, 0.001)
        ->and($fields['misc_fees'])->toBeNull();
});

it('fills missing total from subtotal + tax when items confirm subtotal', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuMissingTotalResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 143.21);

    $fields = $result['fields'];

    expect($fields['subtotal'])->toEqualWithDelta(132.60, 0.001)
        ->and($fields['total_tax'])->toEqualWithDelta(10.61, 0.001)
        ->and($fields['total'])->toEqualWithDelta(143.21, 0.001);
});

it('does not invent a subtotal when items sum disagrees with total - tax', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuMismatchedItemsResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 143.21);

    $fields = $result['fields'];

    // Subtotal must NOT be filled in (items don't reconcile with summary).
    // The existing misc_fees gap logic will absorb the unexplained difference.
    expect($fields['subtotal'])->not->toEqualWithDelta(50.00, 0.001)
        ->and($fields['total'])->toEqualWithDelta(143.21, 0.001)
        ->and($fields['total_tax'])->toEqualWithDelta(10.61, 0.001);
});
