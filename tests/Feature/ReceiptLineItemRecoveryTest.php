<?php

use App\Http\Controllers\ReceiptController;
use App\Services\ContentUnderstandingService;
use Illuminate\Support\Facades\Http;

/**
 * Climate Solutions invoice 68405 (expense 28901, 2026-10-20): three pages,
 * twelve lines, "Taxable Subtotal $13,402.26", stored with eight lines and no
 * subtotal. The invoice repeats the same product on several lines without
 * item codes, and deduplicateLineItems() threw the repeats away; CU's
 * subtotal field missed the "Taxable Subtotal" label.
 */
function climateSolutionsLine(string $description, float $quantity, float $price, float $total): array
{
    return ['valueObject' => [
        'Description' => ['valueString' => $description],
        'Quantity' => ['valueNumber' => $quantity],
        'Price' => ['valueNumber' => $price],
        'TotalPrice' => ['valueNumber' => $total],
    ]];
}

function climateSolutionsCuResult(array $lines): array
{
    $content = <<<'TXT'
CLIMATE SOLUTIONS WINDOWS
Invoice 68405
Customer PO:	BATES GREG
LINE	QTY	DESCRIPTION	COLOR	FACTORY SIZE (W x H)	UNIT PRICE	SUBTOTAL
1	3 8100 2-Lite Slider	White	61 x 20.25	Price	306.55	919.65
Base	275.51	826.53
2	1 8100 2-Lite Slider	White	53 x 28.25	Price	306.55	306.55
3	1 8000 Twin Casement	White	72.5 x 37	Price	763.72	763.72
6	1 8000 CA/CP/CS Triple Vertical	White-LightOak	47.25 x 80.5	Price	1,495.86	1,495.86
7	3 8000 CA/CP/CS Vertical X	White-LightOak	44.75 x 50	Price	919.71	2,759.13
8	2 C-Mull 1/2 Reinforced	White-LightOak	Price	85.00	170.00
9	1 8100 2-Lite Slider	White	63 x 28.25	Price	306.55	306.55
10	1 8000 Casement Single Vent	White	35 5/8 x 37.25	Price	357.36	357.36
12	2 8000 CA/CP/CS Triple Vertical	White-LightOak	47 x 80.5	Price	1,495.86	2,991.72
13	2 8000 CA/CP/CS Triple Vertical	White-LightOak	47 x 80.5	Price	1,495.86	2,991.72
14	2 C-Mull 1/2 Reinforced	White-LightOak	Price	85.00	170.00
15	2 C-Mull 1/2 Reinforced	White-LightOak	Price	85.00	170.00
Taxable Subtotal	$13,402.26
Tax	$1,340.24
Nontaxable Subtotal	$0.00
Delivery	$0.00
Misc. Charges	$0.00
INVOICE TOTAL	$14,742.50
TXT;

    return [
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'CLIMATE SOLUTIONS WINDOWS'],
                    'Total' => ['valueNumber' => 14742.50],
                    'TotalTax' => ['valueNumber' => 1340.24],
                    'TransactionDate' => ['valueDate' => '2026-10-16'],
                    'Items' => ['valueArray' => $lines],
                ],
            ]],
            'content' => $content,
            'styles' => [],
        ],
    ];
}

/** Eight of the lines (what was stored): 9, 13, 14 and 15 are missing. */
function climateSolutionsCuLines(): array
{
    return [
        climateSolutionsLine('8100 2-Lite Slider', 3, 306.55, 919.65),
        climateSolutionsLine('8100 2-Lite Slider', 1, 306.55, 306.55),
        climateSolutionsLine('8000 Twin Casement', 1, 763.72, 763.72),
        climateSolutionsLine('8000 CA/CP/CS Triple Vertical', 1, 1495.86, 1495.86),
        climateSolutionsLine('8000 CA/CP/CS Vertical X', 3, 919.71, 2759.13),
        climateSolutionsLine('C-Mull 1/2 Reinforced', 2, 85.00, 170.00),
        climateSolutionsLine('8000 Casement Single Vent', 1, 357.36, 357.36),
        climateSolutionsLine('8000 CA/CP/CS Triple Vertical', 2, 1495.86, 2991.72),
    ];
}

/** All twelve lines, as a model reading the whole text answers. */
function climateSolutionsRecoveredLines(): array
{
    $line = fn (string $no, string $description, float $qty, float $price, float $total) => [
        'line_number' => $no, 'description' => $description, 'vendor_code' => null,
        'quantity' => $qty, 'unit_price' => $price, 'total_price' => $total,
    ];

    return [
        $line('1', '8100 2-Lite Slider', 3, 306.55, 919.65),
        $line('2', '8100 2-Lite Slider', 1, 306.55, 306.55),
        $line('3', '8000 Twin Casement', 1, 763.72, 763.72),
        $line('6', '8000 CA/CP/CS Triple Vertical', 1, 1495.86, 1495.86),
        $line('7', '8000 CA/CP/CS Vertical X', 3, 919.71, 2759.13),
        $line('8', 'C-Mull 1/2 Reinforced', 2, 85.00, 170.00),
        $line('9', '8100 2-Lite Slider', 1, 306.55, 306.55),
        $line('10', '8000 Casement Single Vent', 1, 357.36, 357.36),
        $line('12', '8000 CA/CP/CS Triple Vertical', 2, 1495.86, 2991.72),
        $line('13', '8000 CA/CP/CS Triple Vertical', 2, 1495.86, 2991.72),
        $line('14', 'C-Mull 1/2 Reinforced', 2, 85.00, 170.00),
        $line('15', 'C-Mull 1/2 Reinforced', 2, 85.00, 170.00),
    ];
}

function readClimateSolutionsInvoice(array $cuLines): array
{
    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn(climateSolutionsCuResult($cuLines));
    app()->instance(ContentUnderstandingService::class, $mock);

    return app(ReceiptController::class)->extractReceipt('/tmp/fake.pdf', 'pdf')['fields'];
}

function fakeRecoveredLines(array $lines): void
{
    config(['services.openai.receipt_item_recovery' => true, 'services.openai.api_key' => 'test-key']);

    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode(['items' => $lines])]]],
    ])]);
}

it('reads a "Taxable Subtotal" that CU missed off the text when it adds up to the total', function () {
    $fields = readClimateSolutionsInvoice(climateSolutionsCuLines());

    expect($fields['subtotal'])->toEqualWithDelta(13402.26, 0.001)
        ->and($fields['total'])->toEqualWithDelta(14742.50, 0.001)
        ->and($fields['misc_fees'])->toBeNull();
});

it('re-reads every line when CU\'s lines fall short of the subtotal', function () {
    fakeRecoveredLines(climateSolutionsRecoveredLines());

    $fields = readClimateSolutionsInvoice(climateSolutionsCuLines());

    expect($fields['items'])->toHaveCount(12)
        ->and(round(array_sum(array_column($fields['items'], 'TotalPrice')), 2))->toBe(13402.26)
        ->and($fields['items'][6])->toMatchArray(['Description' => '8100 2-Lite Slider', 'Quantity' => 1.0, 'TotalPrice' => 306.55]);

    Http::assertSentCount(1);
});

it('keeps CU\'s lines when the re-read does not add up to the subtotal', function () {
    $doubleCounted = climateSolutionsRecoveredLines();
    $doubleCounted[] = ['line_number' => null, 'description' => 'Base', 'vendor_code' => null, 'quantity' => 3, 'unit_price' => 275.51, 'total_price' => 826.53];
    fakeRecoveredLines($doubleCounted);

    $fields = readClimateSolutionsInvoice(climateSolutionsCuLines());

    expect($fields['items'])->toHaveCount(8);
});

it('keeps the lines an invoice repeats when they are what adds up to the subtotal', function () {
    fakeRecoveredLines(climateSolutionsRecoveredLines());

    $all = array_map(fn (array $l) => climateSolutionsLine($l['description'], $l['quantity'], $l['unit_price'], $l['total_price']), climateSolutionsRecoveredLines());
    $fields = readClimateSolutionsInvoice($all);

    expect($fields['items'])->toHaveCount(12);
    Http::assertNothingSent();
});
