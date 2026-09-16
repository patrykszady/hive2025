<?php

use App\Services\EstimateAI\LineItemStreamParser;

/**
 * Feeds a draft to the parser in pieces of the given size and returns the
 * items in the order they were handed back, tagged with the piece that
 * completed each one.
 *
 * @return array{items: list<array<string, mixed>>, at: list<int>}
 */
function streamInPieces(string $document, int $size): array
{
    $parser = new LineItemStreamParser;
    $items = [];
    $at = [];

    foreach (str_split($document, $size) as $piece => $chunk) {
        foreach ($parser->push($chunk) as $item) {
            $items[] = $item;
            $at[] = $piece;
        }
    }

    return ['items' => $items, 'at' => $at];
}

test('each line item comes out the moment its closing brace lands, whatever the chunk boundaries', function () {
    $document = json_encode([
        'reasoning' => 'Hall bath: floor, walls and a new fan.',
        'line_items' => [
            ['line_item_id' => 11, 'quantity' => 1],
            ['line_item_id' => 12, 'quantity' => 48.5],
            ['line_item_id' => 13, 'quantity' => 2],
        ],
    ]);

    foreach ([1, 3, 7, 64, strlen($document)] as $size) {
        $result = streamInPieces($document, $size);

        expect($result['items'])->toBe([
            ['line_item_id' => 11, 'quantity' => 1],
            ['line_item_id' => 12, 'quantity' => 48.5],
            ['line_item_id' => 13, 'quantity' => 2],
        ], "chunk size {$size}");

        // Every item arrives before the document ends, and in order.
        if ($size < 20) {
            expect($result['at'][0])->toBeLessThan($result['at'][2], "chunk size {$size}");
        }
    }
});

test('braces and the words line_items inside strings never count, and nested objects stay part of their item', function () {
    $document = '{"reasoning":"Owner wrote: {\"line_items\": [ nope ]} — treat as {one} job","line_items":['
        .'{"line_item_id":21,"quantity":3,"meta":{"why":"{because}"}},'
        .'{"line_item_id":22,"quantity":1,"note":"tricky \\" quote } here"}'
        .'],"trailing":"{"}';

    $result = streamInPieces($document, 5);

    expect($result['items'])->toBe([
        ['line_item_id' => 21, 'quantity' => 3, 'meta' => ['why' => '{because}']],
        ['line_item_id' => 22, 'quantity' => 1, 'note' => 'tricky " quote } here'],
    ]);
});

test('nothing is emitted after the array closes, and an unfinished item never is', function () {
    $parser = new LineItemStreamParser;

    expect($parser->push('{"line_items":[{"line_item_id":31,"quantity":2'))->toBe([])
        ->and($parser->finished())->toBeFalse()
        ->and($parser->push('},{"line_item_id":32,"qua'))->toBe([['line_item_id' => 31, 'quantity' => 2]])
        ->and($parser->push('ntity":1}]}'))->toBe([['line_item_id' => 32, 'quantity' => 1]])
        ->and($parser->finished())->toBeTrue()
        ->and($parser->push('{"line_item_id":33,"quantity":1}'))->toBe([]);
});

test('an object inside the array that is not valid JSON on its own is skipped, not fatal', function () {
    $parser = new LineItemStreamParser;

    expect($parser->push('{"line_items":[{"line_item_id":41,"quantity":,},{"line_item_id":42,"quantity":1}]}'))
        ->toBe([['line_item_id' => 42, 'quantity' => 1]]);
});
