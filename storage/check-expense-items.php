<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$expense = \App\Models\Expense::find(26736);
echo 'Expense: ' . $expense->id . ' invoice: ' . $expense->invoice . PHP_EOL;

// Build OCR data matching the email body's line items
$ocrData = [
    'content' => 'Virginia Tile Order Update: Your Order is Complete! (552082)',
    'fields' => [
        'items' => [
            ['ProductCode' => 'VTSMSARPENNYH', 'Description' => 'MOSCATO ARGENTO 1-1/4" PENNY ROUND MOSAIC HONED', 'Status' => 'RECEIVED (WAS BO)', 'Quantity' => '9.900', 'UnitPrice' => '$17.25'],
            ['ProductCode' => 'WOWYKDS25', 'Description' => 'YOKO DAISY 3X5', 'Status' => 'RECEIVED (WAS BO)', 'Quantity' => '121.410', 'UnitPrice' => '$12.45'],
            ['ProductCode' => 'VTSMSARBARSMH', 'Description' => 'MOSCATO ARGENTO 1/2X12 BAR LINER HONED', 'Status' => 'RECEIVED (WAS BO)', 'Quantity' => '9.000', 'UnitPrice' => '$6.75'],
            ['ProductCode' => 'CAEPOER1224R', 'Description' => 'PORTRAITS ERICE 12X24 MATTE RECT', 'Status' => 'OPEN LINE', 'Quantity' => '40.680', 'UnitPrice' => '$4.50'],
            ['ProductCode' => 'CAEPOER1224R', 'Description' => 'PORTRAITS ERICE 12X24 MATTE RECT', 'Status' => 'TRANSFER ARRIVED AND CHECKED-IN', 'Quantity' => '122.040', 'UnitPrice' => '$4.50'],
            ['ProductCode' => 'ANALMCP1224HN', 'Description' => 'LA MARCA CALACATTA PAONAZZO HONED 12X24 RECT NEW PKG', 'Status' => 'TRANSFER ARRIVED AND CHECKED-IN', 'Quantity' => '108.500', 'UnitPrice' => '$4.80'],
        ],
        'invoice_number' => '552082',
        'total' => '3071.10',
    ],
];

$emailDate = '2026-04-08';
$emailSubject = 'Fw: Virginia Tile Order Update: Your Order is Complete! (552082)';

$controller = app(\App\Http\Controllers\CompanyEmailController::class);

// Use reflection to call the protected method
$method = new ReflectionMethod($controller, 'mergeMaterialOrderUpdate');
$method->setAccessible(true);
$method->invoke($controller, $expense, $ocrData, $emailDate, $emailSubject);

echo PHP_EOL . '--- AFTER MERGE ---' . PHP_EOL;

// Re-fetch and show updated items
$receipt = \App\Models\ExpenseReceipts::where('expense_id', $expense->id)
    ->where('is_material_order', true)
    ->first();

$items = $receipt->receipt_items['items'] ?? [];
echo 'Items: ' . count($items) . PHP_EOL;
foreach ($items as $i => $item) {
    echo $i . ': ' . ($item['Description'] ?? 'N/A')
        . ' | ' . ($item['Status'] ?? 'N/A')
        . ' | ETA: ' . ($item['ETA'] ?? 'N/A')
        . ' | Code: ' . ($item['ProductCode'] ?? 'N/A')
        . PHP_EOL;
}
