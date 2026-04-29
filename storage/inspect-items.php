<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$receipt = App\Models\ExpenseReceipts::find(16907);
$items = $receipt->receipt_items['items'] ?? [];
echo "total items: ".count($items)."\n\n";
foreach ($items as $idx => $item) {
    $vc = $item['VendorCode'] ?? '';
    echo "idx=$idx VC=$vc desc=".substr($item['Description']??'',0,60)."\n";
    echo "  url: ".($item['product_url']??'(none)')."\n";
    echo "  img: ".($item['image_url']??'(none)')."\n";
}
