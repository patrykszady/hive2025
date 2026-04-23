<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeReceiptItemImagesV2;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Console\Command;

/**
 * Benchmark V2 scraper synchronously against a receipt.
 * Clears existing image_url/product_url + cache, runs V2 in-process,
 * times each item, then prints per-item results.
 *
 * Usage: php artisan receipts:scrape-v2 --receipt=16815
 */
class ScrapeV2BenchCommand extends Command
{
    protected $signature = 'receipts:scrape-v2
                            {--receipt= : ExpenseReceipts ID}
                            {--keep : Do not clear existing data first}';

    protected $description = 'Run ScrapeReceiptItemImagesV2 synchronously and report per-item results';

    public function handle(): int
    {
        $receiptId = $this->option('receipt');
        if (! $receiptId) {
            $this->error('--receipt is required');
            return self::FAILURE;
        }

        $receipt = ExpenseReceipts::find($receiptId);
        if (! $receipt) {
            $this->error("Receipt {$receiptId} not found");
            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            $this->info("Clearing existing url/image data + cache for receipt {$receiptId}");
            $ri = $receipt->receipt_items;
            foreach ($ri['items'] as $i => $it) {
                $ri['items'][$i]['product_url'] = null;
                $ri['items'][$i]['image_url']   = null;
            }
            $receipt->receipt_items = $ri;
            $receipt->save();
            ReceiptLineItemDesc::where('expense_receipt_id', $receiptId)->delete();
            $receipt = $receipt->fresh();
        }

        $start = microtime(true);
        $this->info("Running V2 scraper synchronously...");

        $job = new ScrapeReceiptItemImagesV2($receipt);
        $job->handle();

        $elapsed = round(microtime(true) - $start, 2);

        $receipt = $receipt->fresh();
        $items   = $receipt->receipt_items['items'] ?? [];
        $total   = count($items);
        $withUrl = 0;
        $withImg = 0;

        $rows = [];
        foreach ($items as $idx => $it) {
            $url = $it['product_url'] ?? null;
            $img = $it['image_url']   ?? null;
            if ($url) $withUrl++;
            if ($img) $withImg++;
            $rows[] = [
                $idx,
                $it['ManufacturerPartNumber'] ?? '',
                substr($it['Description'] ?? '', 0, 35),
                $url ? 'Y' : 'N',
                $img ? 'Y' : 'N',
                $url ? (parse_url($url, PHP_URL_HOST) ?: '') : '',
            ];
        }

        $this->table(['#', 'MPN', 'Description', 'URL', 'IMG', 'Host'], $rows);
        $this->info("Time: {$elapsed}s | URL: {$withUrl}/{$total} | IMG: {$withImg}/{$total}");

        return self::SUCCESS;
    }
}
