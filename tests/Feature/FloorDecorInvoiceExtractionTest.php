<?php

use App\Console\Commands\BackfillFloorDecorInvoiceNumbers;

// ── BackfillFloorDecorInvoiceNumbers regex ────────────────────────────────────

it('matches transaction number in Floor & Decor raw content (single newline)', function () {
    $command = new BackfillFloorDecorInvoiceNumbers();
    $regex = (new ReflectionProperty($command, 'transactionRegex'))->getValue($command);

    $raw = "Transaction Number\n1013601611501618\n\nStore\n136";
    expect(preg_match($regex, $raw, $m))->toBe(1)
        ->and(trim($m[1]))->toBe('1013601611501618');
});

it('matches transaction number in Floor & Decor raw content (double newline)', function () {
    $command = new BackfillFloorDecorInvoiceNumbers();
    $regex = (new ReflectionProperty($command, 'transactionRegex'))->getValue($command);

    $raw = "Transaction Number\n\n1021301611213986\n\nStore\n213";
    expect(preg_match($regex, $raw, $m))->toBe(1)
        ->and(trim($m[1]))->toBe('1021301611213986');
});

it('does not match when there is no transaction number in raw content', function () {
    $command = new BackfillFloorDecorInvoiceNumbers();
    $regex = (new ReflectionProperty($command, 'transactionRegex'))->getValue($command);

    $raw = "Invoice Number: 12EGHHD123600428\nSubtotal: $38.99";
    expect(preg_match($regex, $raw, $m))->toBe(0);
});

it('does not match short numeric strings that are not transaction numbers', function () {
    $command = new BackfillFloorDecorInvoiceNumbers();
    $regex = (new ReflectionProperty($command, 'transactionRegex'))->getValue($command);

    // 5-digit store number should not match (min 10 digits required)
    $raw = "Transaction Number\n12345\n";
    expect(preg_match($regex, $raw, $m))->toBe(0);
});
