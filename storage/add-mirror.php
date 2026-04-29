<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use App\Jobs\ScrapeReceiptItemImagesV2;

$r  = ExpenseReceipts::find(16907);
$ri = $r->receipt_items;

// 1) Upgrade Caxton sink (idx=3) image to higher-res scene7 variant
$ri['items'][3]['image_url'] = 'https://kohler.scene7.com/is/image/PAWEB/20000-0_ISO_d2c0046112_rgb?wid=2500';

// 2) Append the missing Essential mirror as a new item (matches existing item shape)
$template = $ri['items'][0];
$newIdx = count($ri['items']);
$mirror = [];
foreach (array_keys($template) as $k) {
    $mirror[$k] = null;
}
$mirror['Description']            = 'KOHLER ESSENTIAL 22 X 34 RECTANGLE DECORATIVE MIRROR MATTE BLACK';
$mirror['Manufacturer']           = 'Kohler';
$mirror['ManufacturerPartNumber'] = 'K26052BLL';
$mirror['Quantity']               = 2;
$mirror['VendorCode']             = null;
$mirror['product_url']            = null;
$mirror['image_url']              = null;
$ri['items'][$newIdx] = $mirror;

$r->receipt_items = $ri;
$r->save();

// Clear cache for the new index just in case
ReceiptLineItemDesc::where('expense_receipt_id', 16907)
    ->where('item_index', $newIdx)
    ->delete();

ScrapeReceiptItemImagesV2::dispatch($r);

echo "upgraded idx=3 image (wid=2500)\n";
echo "appended mirror at idx=$newIdx (Kohler K26052BLL qty=2)\n";
echo "dispatched scraper\n";
