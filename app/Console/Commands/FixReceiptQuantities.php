<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

class FixReceiptQuantities extends Command
{
    protected $signature = 'receipts:fix-quantities
        {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'One-time fix: correct receipt line item quantities that defaulted to 1 when OCR missed the quantity field (March 2026)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'DRY RUN — no changes will be saved.' : 'Fixing receipt quantities...');
        $this->newLine();

        $fixed = 0;
        $itemsFixed = 0;
        $skipped = 0;

        ExpenseReceipts::query()
            ->whereNotNull('receipt_items')
            ->where('receipt_items', '!=', 'null')
            ->cursor()
            ->each(function (ExpenseReceipts $receipt) use ($dryRun, &$fixed, &$itemsFixed, &$skipped) {
                $data = $receipt->receipt_items;
                $items = $data['items'] ?? [];
                $content = $receipt->receipt_html ?? '';

                if (empty($items)) {
                    return;
                }

                $changes = [];

                // Pass 1: Price-based calculation (TotalPrice / Price = clean integer)
                foreach ($items as $key => &$item) {
                    $qty = $item['Quantity'] ?? 1;
                    $price = $item['Price'] ?? 0;
                    $total = $item['TotalPrice'] ?? 0;

                    if ($qty != 1 || $price <= 0 || $total <= $price) {
                        continue;
                    }

                    $calc = $total / $price;
                    $rounded = round($calc);

                    if ($rounded > 1 && abs($calc - $rounded) < 0.01) {
                        $oldQty = $item['Quantity'];
                        $item['Quantity'] = (int) $rounded;
                        $changes[] = [
                            'method' => 'price-calc',
                            'description' => mb_substr($item['Description'] ?? '(no desc)', 0, 50),
                            'old_qty' => $oldQty,
                            'new_qty' => (int) $rounded,
                            'price' => $price,
                            'total' => $total,
                        ];
                    }
                }
                unset($item);

                // Pass 2: Content-based extraction from raw OCR text
                if ($content !== '') {
                    $lines = preg_split('/\n/', html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    foreach ($items as $key => &$item) {
                        $qty = $item['Quantity'] ?? 1;
                        $price = $item['Price'] ?? 0;
                        $total = $item['TotalPrice'] ?? 0;

                        if ($qty != 1 || $price <= 0 || $total <= $price) {
                            continue;
                        }

                        $totalStr = number_format($total, 2, '.', ',');
                        $totalStrNoComma = number_format($total, 2, '.', '');

                        foreach ($lines as $line) {
                            if (strpos($line, $totalStr) === false && strpos($line, $totalStrNoComma) === false) {
                                continue;
                            }

                            preg_match_all('/(\d+(?:,\d{3})*(?:\.\d+)?)/', $line, $nums);
                            if (empty($nums[1]) || count($nums[1]) < 3) {
                                continue;
                            }

                            $numbers = array_map(fn ($n) => (float) str_replace(',', '', $n), $nums[1]);

                            $totalIdx = null;
                            foreach ($numbers as $i => $num) {
                                if (abs($num - $total) < 0.01) {
                                    $totalIdx = $i;
                                    break;
                                }
                            }

                            if ($totalIdx === null) {
                                continue;
                            }

                            $priceIdx = null;
                            foreach ($numbers as $i => $num) {
                                if ($i === $totalIdx) {
                                    continue;
                                }
                                if (abs($num - $price) < 0.01) {
                                    $priceIdx = $i;
                                }
                            }

                            foreach ($numbers as $i => $num) {
                                if ($i === $totalIdx || $i === $priceIdx) {
                                    continue;
                                }
                                if ($num < 1 || $num != floor($num)) {
                                    continue;
                                }

                                $expectedTotal = $num * $price;
                                if (abs($expectedTotal - $total) < 0.01) {
                                    $item['Quantity'] = (int) $num;
                                    $changes[] = [
                                        'method' => 'content',
                                        'description' => mb_substr($item['Description'] ?? '(no desc)', 0, 50),
                                        'old_qty' => 1,
                                        'new_qty' => (int) $num,
                                        'price' => $price,
                                        'total' => $total,
                                    ];
                                    break 2;
                                }
                            }
                        }
                    }
                    unset($item);
                }

                if (empty($changes)) {
                    $skipped++;

                    return;
                }

                $this->line("Receipt #{$receipt->id} (expense #{$receipt->expense_id}):");
                $this->table(
                    ['Method', 'Description', 'Old Qty', 'New Qty', 'Unit Price', 'Total'],
                    collect($changes)->map(fn ($c) => [
                        $c['method'],
                        $c['description'],
                        $c['old_qty'],
                        $c['new_qty'],
                        '$'.number_format($c['price'], 2),
                        '$'.number_format($c['total'], 2),
                    ])->toArray()
                );

                if (! $dryRun) {
                    $data['items'] = $items;
                    $receipt->receipt_items = $data;
                    $receipt->save();
                }

                $fixed++;
                $itemsFixed += count($changes);
            });

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] Would fix' : 'Fixed')." {$itemsFixed} line items across {$fixed} receipts. Skipped {$skipped} receipts (no changes needed).");

        return self::SUCCESS;
    }
}
