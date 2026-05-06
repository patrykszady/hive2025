<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;

$dir = '_temp_ocr/dedup-test';
$files = ['att-0-Epson_05052026162803(1).pdf', 'att-1-Epson_05052026162803(2).pdf', 'att-2-Epson_05052026162803(3).pdf'];
$ctrl = app(\App\Http\Controllers\ReceiptController::class);
$results = [];
foreach ($files as $i => $f) {
    $path = $dir.'/'.$f;
    if (!Storage::disk('files')->exists($path)) {
        // Move from app/ disk to files/ disk path
        $bin = file_get_contents(storage_path('app/'.$path));
        Storage::disk('files')->put($path, $bin);
    }
    echo "=== Analyzing [$i] $f ===\n";
    $r = $ctrl->extractReceipt($path, 'pdf', null, true);
    if (!empty($r['error'])) {
        echo "  ERROR: ".json_encode($r)."\n";
        continue;
    }
    $f2 = $r['fields'] ?? [];
    echo "  merchant: ".($f2['merchant_name']??'')."\n";
    echo "  total: ".($f2['total']??'')."\n";
    echo "  subtotal: ".($f2['subtotal']??'')."\n";
    echo "  tax: ".($f2['total_tax']??'')."\n";
    echo "  date: ".($f2['transaction_date']??'')."\n";
    echo "  invoice: ".($f2['invoice_number']??'')."\n";
    echo "  PO: ".($f2['purchase_order']??'')."\n";
    echo "  items count: ".count($f2['items']??[])."\n";
    echo "  payments: ".json_encode($f2['payment_methods']??[])."\n";
    $results[$i] = $f2;
}
file_put_contents(storage_path('app/_temp_ocr/dedup-test/_results.json'), json_encode($results, JSON_PRETTY_PRINT));
echo "\nResults saved.\n";
