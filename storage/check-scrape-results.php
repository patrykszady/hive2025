<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$r = App\Models\ExpenseReceipts::find(16785);
$items = $r->receipt_items['items'];
$urls = 0;
$imgs = 0;
$total = count($items);

foreach ($items as $i => $item) {
    $u = ! empty($item['product_url']) ? 'Y' : 'N';
    $m = ! empty($item['image_url']) ? 'Y' : 'N';
    if ($u === 'Y') $urls++;
    if ($m === 'Y') $imgs++;
    $name = substr($item['Description'] ?? $item['name'] ?? '?', 0, 55);
    echo sprintf("%2d. URL:%s IMG:%s  %s\n", $i, $u, $m, $name);
}

echo "\nURLs: {$urls}/{$total}, Images: {$imgs}/{$total}\n";
