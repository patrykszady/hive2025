<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use App\Jobs\ScrapeReceiptItemImagesV2;

$receipt = ExpenseReceipts::find(16907);
$ri = $receipt->receipt_items;
$items = $ri['items'] ?? [];

// Per-item actions:
//   url=true means clear product_url
//   img=true means clear image_url
//   retry=true means delete cache row so scraper re-resolves
$actions = [
    4  => ['url' => false, 'img' => true,  'retry' => true],   // 3075697 (wrong image)
    7  => ['url' => true,  'img' => true,  'retry' => true],   // 3419587 (wrong url+img)
    17 => ['url' => false, 'img' => true,  'retry' => true],   // 686266 (small image)
    26 => ['url' => true,  'img' => true,  'retry' => true],   // 643511 (wrong url+img)
];

foreach ($actions as $idx => $a) {
    if (!isset($items[$idx])) { continue; }
    if ($a['url']) { $items[$idx]['product_url'] = null; }
    if ($a['img']) { $items[$idx]['image_url']   = null; }
    if ($a['retry']) {
        ReceiptLineItemDesc::where('expense_receipt_id', 16907)
            ->where('item_index', $idx)
            ->delete();
    }
    echo "cleared idx=$idx VC=".($items[$idx]['VendorCode']??'?')."\n";
}

$ri['items'] = $items;
$receipt->receipt_items = $ri;
$receipt->save();

ScrapeReceiptItemImagesV2::dispatch($receipt);
echo "re-dispatched scraper\n";
