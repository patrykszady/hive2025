<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$svc = app(\App\Services\ContentUnderstandingService::class);
$result = $svc->analyze('_temp_ocr/Order-552082.pdf', 'pdf', 'stack', 'hive_MaterialOrder_1');
$items = $result['analyzeResult']['documents'][0]['fields']['Items']['valueArray'] ?? [];
echo 'CustomerPO: ' . ($result['analyzeResult']['documents'][0]['fields']['CustomerPO']['valueString'] ?? 'NULL') . PHP_EOL;
echo 'Items: ' . count($items) . PHP_EOL;
foreach ($items as $i => $item) {
    $o = $item['valueObject'];
    $ln = $o['LineNumber']['valueString'] ?? 'NULL';
    $in = $o['ItemNumber']['valueString'] ?? 'NULL';
    $desc = $o['Description']['valueString'] ?? 'NULL';
    $area = $o['Area']['valueString'] ?? 'NULL';
    $notes = $o['Notes']['valueString'] ?? 'NULL';
    $qty = $o['Quantity']['valueString'] ?? 'NULL';
    $up = $o['UnitPrice']['valueNumber'] ?? 'NULL';
    $tp = $o['TotalPrice']['valueNumber'] ?? 'NULL';
    echo PHP_EOL . "[$i] Line=$ln Item=$in" . PHP_EOL;
    echo "  Desc: " . substr($desc, 0, 80) . PHP_EOL;
    echo "  Area: $area" . PHP_EOL;
    echo "  Notes: " . substr(str_replace("\n", ' ', $notes), 0, 150) . PHP_EOL;
    echo "  Qty=$qty UP=$up TP=$tp" . PHP_EOL;
}
