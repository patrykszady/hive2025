<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

/**
 * Backfill missing subtotal / total_tax / total fields on existing
 * ExpenseReceipts rows when the remaining summary fields plus the line
 * items make the missing value unambiguously derivable.
 *
 * Mirrors the post-OCR reconciliation in
 * ReceiptController::reconcileReceiptTotals() so future OCR runs and
 * this backfill stay in sync.
 */
class BackfillReceiptTotals extends Command
{
    protected $signature = 'receipts:backfill-totals
                            {--dry-run : Show what would change without writing}
                            {--id=* : Limit to one or more ExpenseReceipts ids}
                            {--expense-id=* : Limit to receipts for one or more expense ids}';

    protected $description = 'Reconcile missing subtotal/tax/total on existing ExpenseReceipts using line-items math.';

    public function handle(): int
    {
        $tolerance = 0.02;

        $query = ExpenseReceipts::query()
            ->whereNotNull('receipt_items');

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }
        if ($expenseIds = $this->option('expense-id')) {
            $query->whereIn('expense_id', $expenseIds);
        }

        $updated = 0;
        $skipped = 0;
        $checked = 0;
        $dryRun = (bool) $this->option('dry-run');

        $query->chunkById(200, function ($chunk) use (&$updated, &$skipped, &$checked, $tolerance, $dryRun) {
            foreach ($chunk as $receipt) {
                $checked++;
                $data = $receipt->receipt_items;
                if (!is_array($data)) {
                    $skipped++;
                    continue;
                }

                $subtotal = $this->toFloat($data['subtotal'] ?? null);
                $totalTax = $this->toFloat($data['total_tax'] ?? null);
                $total    = $this->toFloat($data['total'] ?? null);
                $tip      = $this->toFloat($data['tip'] ?? null) ?? 0.0;
                $shipping = $this->toFloat($data['shipping'] ?? null) ?? 0.0;
                $sideAdjustments = round($tip + $shipping, 2);

                [$itemsSum, $itemsCount] = $this->itemsSum($data['items'] ?? []);

                $changes = [];

                if ($subtotal === null && $itemsCount > 0 && $itemsSum > 0) {
                    if ($total !== null && $totalTax !== null) {
                        $expected = round($total - $totalTax - $sideAdjustments, 2);
                        if (abs($expected - $itemsSum) <= $tolerance) {
                            $subtotal = $itemsSum;
                            $changes['subtotal'] = $itemsSum;
                        }
                    } elseif ($total === null && $totalTax === null) {
                        $subtotal = $itemsSum;
                        $changes['subtotal'] = $itemsSum;
                    }
                }

                if ($totalTax === null && $subtotal !== null && $total !== null) {
                    $agree = $itemsCount === 0 || abs($itemsSum - $subtotal) <= $tolerance;
                    if ($agree) {
                        $gap = round($total - $subtotal - $sideAdjustments, 2);
                        if ($gap > $tolerance) {
                            $totalTax = $gap;
                            $changes['total_tax'] = $gap;
                        }
                    }
                }

                if ($total === null && $subtotal !== null && $totalTax !== null) {
                    $agree = $itemsCount === 0 || abs($itemsSum - $subtotal) <= $tolerance;
                    if ($agree) {
                        $total = round($subtotal + $totalTax + $sideAdjustments, 2);
                        $changes['total'] = $total;
                    }
                }

                if (empty($changes)) {
                    continue;
                }

                $this->line(sprintf(
                    '#%d (expense %s, %s): %s',
                    $receipt->id,
                    $receipt->expense_id,
                    $receipt->receipt_filename,
                    collect($changes)->map(fn ($v, $k) => "$k=$v")->implode(', ')
                ));

                if (!$dryRun) {
                    $data['subtotal']  = $subtotal;
                    $data['total_tax'] = $totalTax;
                    $data['total']     = $total;
                    $receipt->receipt_items = $data;
                    $receipt->saveQuietly();
                }
                $updated++;
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Checked %d receipt(s); %s %d row(s); skipped %d.',
            $checked,
            $dryRun ? 'would update' : 'updated',
            $updated,
            $skipped
        ));

        return self::SUCCESS;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
            if ($value === null) {
                return null;
            }
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array{0: float, 1: int}  [sum, count]
     */
    private function itemsSum(mixed $items): array
    {
        if (!is_array($items)) {
            return [0.0, 0];
        }
        $sum = 0.0;
        $count = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('TotalPrice', $item)) {
                continue;
            }
            $price = $item['TotalPrice'];
            if ($price === null || $price === '' || !is_numeric($price)) {
                continue;
            }
            $sum += (float) $price;
            $count++;
        }

        return [round($sum, 2), $count];
    }
}

