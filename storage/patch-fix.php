<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use App\Jobs\ScrapeReceiptItemImagesV2;

$r  = ExpenseReceipts::find(16907);
$ri = $r->receipt_items;

// idx => [optional MPN override, optional product_url override]
$patches = [
    0  => [
        // LESS HANDLES Brizo Beauclere widespread lav faucet — pin canonical bare LHP product page
        'product_url' => 'https://www.brizo.com/bath/product/65365LF-PCLHP',
    ],
    2  => [
        // Strip "BRIZO" prefix wrongly concatenated to MPN
        'mpn'         => 'RP72414PC',
        'product_url' => null,
    ],
    3  => [
        // No Home Depot — use Kohler official
        'product_url' => 'https://www.kohler.com/en/products/bathroom-sinks/shop-bathroom-sinks/caxton-rectangle-undermount-bathroom-sink-with-overflow-and-clamp-assembly-20000?skuId=20000-0',
    ],
    7  => [
        // Just the lever handle KIT, not the combined floor-mount filler
        'product_url' => 'https://www.brizo.com/customer-support/repair-parts/HL7065-PC',
    ],
    12 => [
        // LESS HANDLE thermostatic trim — pin canonical bare LHP product page
        'product_url' => 'https://www.brizo.com/bath/product/T66T065-PCLHP',
    ],
];

foreach ($patches as $idx => $p) {
    if (! isset($ri['items'][$idx])) {
        echo "idx=$idx MISSING\n";
        continue;
    }
    if (array_key_exists('mpn', $p)) {
        $ri['items'][$idx]['ManufacturerPartNumber'] = $p['mpn'];
    }
    $ri['items'][$idx]['product_url'] = $p['product_url'] ?? null;
    $ri['items'][$idx]['image_url']   = null;
    echo "patched idx=$idx MPN=".$ri['items'][$idx]['ManufacturerPartNumber']
        .' url='.($ri['items'][$idx]['product_url'] ?? '(null)')."\n";
}

$r->receipt_items = $ri;
$r->save();

$d = ReceiptLineItemDesc::where('expense_receipt_id', 16907)
    ->whereIn('item_index', array_keys($patches))
    ->delete();
echo "deleted $d cache rows\n";

ScrapeReceiptItemImagesV2::dispatch($r);
echo "dispatched\n";
