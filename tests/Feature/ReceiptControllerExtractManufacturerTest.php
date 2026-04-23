<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;

/**
 * Builds a minimal CU analyzeResult containing one line item with the given
 * description and no Manufacturer/ManufacturerPartNumber fields, so that the
 * fallback regex in extractReceipt() is exercised.
 */
function fakeCuResultWithDescription(string $description): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'Items' => [
                        'valueArray' => [[
                            'valueObject' => [
                                'Description' => ['valueString' => $description],
                            ],
                        ]],
                    ],
                ],
            ]],
            'content' => '',
            'styles' => [],
        ],
    ];
}

function extractFirstItem(string $description): array
{
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')
        ->once()
        ->andReturn(fakeCuResultWithDescription($description));
    app()->instance(ContentUnderstandingService::class, $mock);

    $controller = app(ReceiptController::class);
    $result = $controller->extractReceipt('/tmp/fake.pdf', 'material_order', expenseAmount: 1.0);

    return $result['fields']['items'][0] ?? [];
}

it('does not extract manufacturer/part number from descriptive hyphenated terms like ROUGH-IN', function () {
    $item = extractFirstItem('PVC ISLAND DRAIN FREESTANDING TUB DRAIN ROUGH-IN');

    expect($item['Manufacturer'] ?? null)->toBeNull();
    expect($item['ManufacturerPartNumber'] ?? null)->toBeNull();
});

it('does not extract manufacturer/part number from descriptive hyphenated terms like RITE-TEMP', function () {
    $item = extractFirstItem('KOHLER UNIVERSAL RITE-TEMP PB VALVE KIT, STOP');

    expect($item['Manufacturer'] ?? null)->toBeNull();
    expect($item['ManufacturerPartNumber'] ?? null)->toBeNull();
});

it('still extracts real manufacturer part numbers that contain digits', function () {
    $item = extractFirstItem('KOHLER K-8304-KS-NA UNIVERSAL RITE-TEMP PB VALVE KIT');

    expect($item['Manufacturer'] ?? null)->toBe('KOHLER');
    expect($item['ManufacturerPartNumber'] ?? null)->toBe('K-8304-KS-NA');
});
