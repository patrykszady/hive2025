<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;

function fakeCuHandwrittenResult(string $handwritten): array
{
    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Total' => ['valueNumber' => 10.00],
                    'HandwrittenNote' => ['valueString' => $handwritten],
                ],
            ]],
            'content' => "MENARDS\nTotal\n$10.00\n",
            'styles' => [],
        ],
    ];
}

function extractWithHandwritten(string $handwritten): array
{
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(fakeCuHandwrittenResult($handwritten));
    app()->instance(ContentUnderstandingService::class, $mock);

    // expenseAmount keeps extractReceipt from bailing on the fake's missing
    // TransactionDate (same approach as the totals-reconciliation tests).
    $result = app(ReceiptController::class)->extractReceipt('/tmp/fake.pdf', 'pdf', expenseAmount: 10.00);

    return $result['fields']['handwritten_notes'] ?? [];
}

it('splits piped handwritten notes into separate entries', function () {
    expect(extractWithHandwritten('912 | R'))->toBe(['912', 'R']);
});

it('treats a literal null/none answer as no handwriting at all', function (string $answer) {
    expect(extractWithHandwritten($answer))->toBe([]);
})->with(['null', 'None', 'N/A', 'no handwritten note']);

it('keeps a real note while dropping a literal null piped beside it', function () {
    expect(extractWithHandwritten('350 E. KENSINGTON | null'))->toBe(['350 E. KENSINGTON']);
});
