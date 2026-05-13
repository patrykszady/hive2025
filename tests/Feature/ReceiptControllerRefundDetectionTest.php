<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;

/**
 * Regression: Home Depot return receipt (expense 26730) was being saved as
 * a positive 11.33 with a phantom 22.66 misc_fees. Azure CU returned Total
 * as a positive absolute value, but every other field (subtotal, tax, line
 * items, payment method) was negative. The misc_fees gap calc
 * (total - (subtotal + tax)) then produced 11.33 - (-11.33) = 22.66.
 */
function fakeCuRefundResult(): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'THE HOME DEPOT'],
                    'Total'        => ['valueNumber' => 11.33],
                    'Subtotal'     => ['valueNumber' => -10.30],
                    'TotalTax'     => ['valueNumber' => -1.03],
                    'Items'        => [
                        'valueArray' => [
                            ['valueObject' => [
                                'Description' => ['valueString' => 'PANCAK BOX'],
                                'Quantity'    => ['valueNumber' => 1],
                                'Price'       => ['valueNumber' => -3.54],
                                'TotalPrice'  => ['valueNumber' => -3.54],
                            ]],
                            ['valueObject' => [
                                'Description' => ['valueString' => '12GREAT STUF'],
                                'Quantity'    => ['valueNumber' => 2],
                                'Price'       => ['valueNumber' => -3.38],
                                'TotalPrice'  => ['valueNumber' => -6.76],
                            ]],
                        ],
                    ],
                ],
            ]],
            'content' => "THE HOME DEPOT\nPANCAK BOX -3.54\n12GREAT STUF -6.76\nSUBTOTAL -10.30\nTAX -1.03\nTOTAL -11.33\n",
            'styles'  => [],
        ],
    ];
}

it('flips sign-flipped CU total on refund receipts and suppresses phantom misc_fees', function () {
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuRefundResult());
    app()->instance(ContentUnderstandingService::class, $mock);

    $result = app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: -11.33);

    $fields = $result['fields'];

    expect($fields['total'])->toBe(-11.33)
        ->and($fields['subtotal'])->toBe(-10.30)
        ->and($fields['total_tax'])->toBe(-1.03)
        ->and($fields['misc_fees'])->toBeNull();
});
