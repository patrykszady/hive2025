<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;

/**
 * Regression: handwritten span offsets reference Azure CU's ORIGINAL content.
 * extractReceipt() previously sliced the post-transform $content (after
 * preg_replace/strip_tags/trim mutations changed string lengths), which
 * silently corrupted offsets and dropped notes (e.g. only "Lady" survived
 * from a receipt that also had "PATRIK HOME" handwritten on it).
 */
function fakeCuResultWithHandwriting(string $content, array $styles, ?string $merchantName = null, ?string $merchantAddress = null): array
{
    $fields = [
        'Total' => ['valueNumber' => 1.0],
    ];
    if ($merchantName !== null) {
        $fields['MerchantName'] = ['valueString' => $merchantName];
    }
    if ($merchantAddress !== null) {
        $fields['MerchantAddress'] = ['valueString' => $merchantAddress];
    }

    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => $fields,
            ]],
            'content' => $content,
            'styles'  => $styles,
        ],
    ];
}

function runExtractWithHandwriting(string $content, array $styles, ?string $merchantName = null, ?string $merchantAddress = null): array
{
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')
        ->once()
        ->andReturn(fakeCuResultWithHandwriting($content, $styles, $merchantName, $merchantAddress));
    app()->instance(ContentUnderstandingService::class, $mock);

    return app(ReceiptController::class)
        ->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 1.0);
}

it('captures all handwritten notes using offsets against the original CU content', function () {
    // Original Azure CU content with leading whitespace + multiple blank lines
    // that the rawContent transforms would collapse, shifting offsets.
    $content = "  \n\nPATRIK HOME\nLady\nGregory's\n\n\n\nIRISH PUB\n";
    //                ^offset 4         ^offset 16
    $styles = [
        [
            'isHandwritten' => true,
            'confidence'    => 0.95,
            'spans'         => [
                ['offset' => strpos($content, 'PATRIK HOME'), 'length' => strlen('PATRIK HOME')],
                ['offset' => strpos($content, 'Lady'),        'length' => strlen('Lady')],
            ],
        ],
    ];

    $result = runExtractWithHandwriting($content, $styles);
    $notes  = $result['fields']['handwritten_notes'] ?? [];

    expect($notes)->toContain('PATRIK HOME');
    expect($notes)->toContain('Lady');
});

it('ignores low-confidence handwritten styles', function () {
    // Use content that intentionally fails the leading-label fallback heuristic
    // (>2 words) so this test isolates the confidence-filter behavior.
    $content = "PATRIK HOME OFFICE NOTE\n";
    $styles = [
        [
            'isHandwritten' => true,
            'confidence'    => 0.3,
            'spans'         => [
                ['offset' => 0, 'length' => strlen('PATRIK HOME OFFICE NOTE')],
            ],
        ],
    ];

    $result = runExtractWithHandwriting($content, $styles);
    $notes  = $result['fields']['handwritten_notes'] ?? [];

    expect($notes)->toBe([]);
});

it('rejects handwritten spans whose tokens all appear in the printed merchant name', function () {
    // Mimics the receipt 26781 case: "Lady Gregory's" stylized printed logo
    // gets misclassified by Azure CU as handwriting. The real handwritten
    // annotation is "PATRIK HOME" written across the top of the receipt.
    $content  = "PATRIK HOME\nLady\nGregory's\nIRISH PUB\n";
    $merchant = "Lady Gregory's";
    $styles = [
        [
            'isHandwritten' => true,
            'confidence'    => 0.95,
            'spans'         => [
                ['offset' => strpos($content, 'PATRIK HOME'), 'length' => strlen('PATRIK HOME')],
                ['offset' => strpos($content, 'Lady'),        'length' => strlen('Lady')],
            ],
        ],
    ];

    $result = runExtractWithHandwriting($content, $styles, $merchant);
    $notes  = $result['fields']['handwritten_notes'] ?? [];

    expect($notes)->toBe(['PATRIK HOME']);
});

it('rejects handwritten spans whose tokens appear in the printed merchant address', function () {
    $content = "MENARDS\nLONG GROVE\nJob # or Name : 400\n";
    $merchant = 'MENARDS';
    $merchantAddress = '2700 LAKE COOK RD LONG GROVE, IL 60047';

    $styles = [
        [
            'isHandwritten' => true,
            'confidence'    => 0.95,
            'spans'         => [
                ['offset' => strpos($content, 'LONG GROVE'), 'length' => strlen('LONG GROVE')],
            ],
        ],
    ];

    $result = runExtractWithHandwriting($content, $styles, $merchant, $merchantAddress);
    $notes  = $result['fields']['handwritten_notes'] ?? [];

    expect($notes)->toBe([]);
});
