<?php

use App\Http\Controllers\ReceiptController;

it('does not capture adjacent billing labels as purchase order from raw content', function () {
    $controller = app(ReceiptController::class);

    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('extractPurchaseOrderFromRawContent');
    $method->setAccessible(true);

    $rawContent = "Customer PO Number:\t\tDiscounts:\t0.00\nOrder Number:\tABC123\n";

    $value = $method->invoke($controller, $rawContent);

    expect($value)->toBe('');
});

it('extracts a real purchase order value from raw content', function () {
    $controller = app(ReceiptController::class);

    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('extractPurchaseOrderFromRawContent');
    $method->setAccessible(true);

    $rawContent = "PO Number: PO-4455\nOrder Number: 1001\n";

    $value = $method->invoke($controller, $rawContent);

    expect($value)->toBe('PO-4455');
});
