<?php

namespace App\Console\Commands;

use App\Models\AutoReceiptEmailBatchItem;
use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot deployment cleanup for expense_receipts_data.
 *
 * Phases (run in this order):
 *  1. Reconcile missing subtotal/total_tax/total when math is unambiguous.
 *  2. Sanitize purchase_order field (clear billing-label and junk values).
 *  3. Deduplicate receipts within an expense (KEEP LATEST id; re-point
 *     auto_receipt_email_batch_items to the survivor, then delete the older
 *     rows).
 *
 * Handwritten_notes are left untouched — we trust the CU OCR output.
 *
 * Defaults to dry-run. Pass --apply to actually write.
 *
 *   php artisan receipts:cleanup-for-deployment
 *   php artisan receipts:cleanup-for-deployment --apply
 *   php artisan receipts:cleanup-for-deployment --apply --skip=dedup
 */
class CleanupReceiptsForDeployment extends Command
{
    protected $signature = 'receipts:cleanup-for-deployment
                            {--apply : Write changes (default is dry-run)}
                            {--skip=* : Skip phases by name: totals, po, dedup}';

    protected $description = 'One-shot deployment cleanup: reconcile totals, sanitize PO, dedup receipts.';

    private const TOLERANCE = 0.02;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $skip = array_map('strtolower', (array) $this->option('skip'));

        $this->info($apply ? '🟢 APPLY mode — changes will be written.' : '🟡 DRY-RUN mode — no changes will be written (pass --apply).');
        $this->newLine();

        $summary = [];

        if (! in_array('totals', $skip, true)) {
            $summary['totals'] = $this->reconcileTotals($apply);
        }
        if (! in_array('po', $skip, true)) {
            $summary['po'] = $this->sanitizePurchaseOrders($apply);
        }
        if (! in_array('dedup', $skip, true)) {
            $summary['dedup'] = $this->dedupReceipts($apply);
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $rows = [];
        foreach ($summary as $phase => $stats) {
            foreach ($stats as $k => $v) {
                $rows[] = [$phase, $k, $v];
            }
        }
        $this->table(['phase', 'metric', 'value'], $rows);

        if (! $apply) {
            $this->newLine();
            $this->warn('No changes written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    /* --------------------------------------------------------------------
     * Phase 1 — reconcile subtotal / total_tax / total
     * -------------------------------------------------------------------- */

    /** @return array<string,int> */
    private function reconcileTotals(bool $apply): array
    {
        $this->info('Phase 1/3: Reconcile subtotal / total_tax / total');

        $checked = 0;
        $updated = 0;

        ExpenseReceipts::query()
            ->whereNotNull('receipt_items')
            ->chunkById(200, function ($chunk) use (&$checked, &$updated, $apply): void {
                foreach ($chunk as $receipt) {
                    $checked++;
                    $data = $receipt->receipt_items;
                    if (! is_array($data)) {
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
                            if (abs($expected - $itemsSum) <= self::TOLERANCE) {
                                $subtotal = $itemsSum;
                                $changes['subtotal'] = $itemsSum;
                            }
                        } elseif ($total === null && $totalTax === null) {
                            $subtotal = $itemsSum;
                            $changes['subtotal'] = $itemsSum;
                        }
                    }

                    if ($totalTax === null && $subtotal !== null && $total !== null) {
                        $agree = $itemsCount === 0 || abs($itemsSum - $subtotal) <= self::TOLERANCE;
                        if ($agree) {
                            $gap = round($total - $subtotal - $sideAdjustments, 2);
                            if ($gap > self::TOLERANCE) {
                                $totalTax = $gap;
                                $changes['total_tax'] = $gap;
                            }
                        }
                    }

                    if ($total === null && $subtotal !== null && $totalTax !== null) {
                        $agree = $itemsCount === 0 || abs($itemsSum - $subtotal) <= self::TOLERANCE;
                        if ($agree) {
                            $total = round($subtotal + $totalTax + $sideAdjustments, 2);
                            $changes['total'] = $total;
                        }
                    }

                    if (empty($changes)) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  #%d (expense %s): %s',
                        $receipt->id,
                        $receipt->expense_id,
                        collect($changes)->map(fn ($v, $k) => "$k=$v")->implode(', ')
                    ));

                    if ($apply) {
                        $data['subtotal']  = $subtotal;
                        $data['total_tax'] = $totalTax;
                        $data['total']     = $total;
                        $receipt->receipt_items = $data;
                        $receipt->saveQuietly();
                    }
                    $updated++;
                }
            });

        return ['checked' => $checked, 'updated' => $updated];
    }

    /* --------------------------------------------------------------------
     * Phase 2 — sanitize purchase_order
     * -------------------------------------------------------------------- */

    /** @return array<string,int> */
    private function sanitizePurchaseOrders(bool $apply): array
    {
        $this->info('Phase 2/3: Sanitize purchase_order');

        $checked = 0;
        $cleared = 0;

        ExpenseReceipts::query()
            ->whereNotNull('receipt_items')
            ->chunkById(500, function ($chunk) use (&$checked, &$cleared, $apply): void {
                foreach ($chunk as $receipt) {
                    $checked++;
                    $data = $receipt->receipt_items;
                    if (! is_array($data) || ! array_key_exists('purchase_order', $data)) {
                        continue;
                    }
                    $po = $data['purchase_order'];
                    if (! is_string($po)) {
                        continue;
                    }
                    $trimmed = trim($po);
                    if ($trimmed === '') {
                        continue;
                    }

                    if (! $this->isPoInvalid($trimmed)) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  #%d (expense %s): purchase_order=%s → null',
                        $receipt->id,
                        $receipt->expense_id,
                        $trimmed
                    ));

                    if ($apply) {
                        $data['purchase_order'] = null;
                        $receipt->receipt_items = $data;
                        $receipt->saveQuietly();
                    }
                    $cleared++;
                }
            });

        return ['checked' => $checked, 'cleared' => $cleared];
    }

    /**
     * Junk PO detector — pattern-only, no name lists. A PO is junk when it is:
     *  • a single character
     *  • made up solely of zeros / letter-O / dots / dashes / pipes / slashes / whitespace
     *  • a "no value" token (n/a, none, no, nil, null, tbd, unknown)
     *  • a label-like fragment that mentions "job name" / "purchase order"
     *  • a billing-summary line such as "Discounts: 0.00", "Tax: 12.34",
     *    "Subtotal #5" — i.e. "<word words>:<digits|symbols>".
     *
     * Names like "Greg" / "Gregory" / initials must NOT match.
     */
    private function isPoInvalid(string $po): bool
    {
        $trimmed = trim($po);

        if (mb_strlen($trimmed) <= 1) {
            return true;
        }

        if (preg_match('/^[0Oo.,\-_|\/\\\s]+$/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^(n\/?a|none|no|nil|null|tbd|unknown)$/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(job\s*name|purchase\s*order)\b/i', $trimmed) === 1) {
            return true;
        }

        // "<words> : <digits/symbols>" or "<words> # <digits/symbols>".
        if (preg_match('/^[a-z][a-z\s\-]{1,30}\s*[:#]\s*[\d.,\-$%\s]*$/i', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    /* --------------------------------------------------------------------
     * Phase 3 — dedup within expense (keep latest id)
     * -------------------------------------------------------------------- */

    /** @return array<string,int> */
    private function dedupReceipts(bool $apply): array
    {
        $this->info('Phase 3/3: Dedup receipts within expense (keep latest id)');

        // Only expenses with ≥2 receipts.
        $expenseIds = ExpenseReceipts::query()
            ->select('expense_id')
            ->groupBy('expense_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('expense_id');

        $this->line(sprintf('  Scanning %d expense(s) with multiple receipts…', $expenseIds->count()));

        $expensesAffected = 0;
        $deleted = 0;
        $pivotsRepointed = 0;
        $pivotsDropped = 0;

        foreach ($expenseIds as $expenseId) {
            // Latest first → we keep heads, dedup tails.
            $receipts = ExpenseReceipts::query()
                ->where('expense_id', $expenseId)
                ->orderByDesc('id')
                ->get(['id', 'receipt_html', 'receipt_items']);

            /** @var array<int, ExpenseReceipts> $survivors */
            $survivors = [];
            $expenseTouched = false;

            foreach ($receipts as $receipt) {
                $survivor = null;
                foreach ($survivors as $s) {
                    if (ExpenseReceipts::matchesAsDuplicate(
                        $s->receipt_html,
                        $s->receipt_items ?? [],
                        $receipt->receipt_html,
                        $receipt->receipt_items ?? [],
                    )) {
                        $survivor = $s;
                        break;
                    }
                }

                if ($survivor === null) {
                    $survivors[] = $receipt;
                    continue;
                }

                $expenseTouched = true;

                $this->line(sprintf(
                    '  expense %d: delete #%d (dup of kept #%d)',
                    $expenseId,
                    $receipt->id,
                    $survivor->id,
                ));

                [$rep, $drop] = $this->repointBatchItems($receipt->id, $survivor->id, $apply);
                $pivotsRepointed += $rep;
                $pivotsDropped += $drop;

                if ($apply) {
                    $receipt->delete();
                }
                $deleted++;
            }

            if ($expenseTouched) {
                $expensesAffected++;
            }
        }

        return [
            'expenses_affected'   => $expensesAffected,
            'receipts_deleted'    => $deleted,
            'pivots_repointed'    => $pivotsRepointed,
            'pivots_dropped'      => $pivotsDropped,
        ];
    }

    /**
     * Re-point auto_receipt_email_batch_items rows from $dupId → $survivorId.
     * Where a (batch_id, attachment_index) row already exists for any other
     * receipt id, the dup's pivot row is dropped instead (unique constraint).
     *
     * @return array{0:int,1:int}  [repointed, dropped]
     */
    private function repointBatchItems(int $dupId, int $survivorId, bool $apply): array
    {
        $items = AutoReceiptEmailBatchItem::query()->where('expense_receipt_id', $dupId)->get();
        $repointed = 0;
        $dropped = 0;

        foreach ($items as $item) {
            $conflict = AutoReceiptEmailBatchItem::query()
                ->where('batch_id', $item->batch_id)
                ->where('attachment_index', $item->attachment_index)
                ->where('id', '!=', $item->id)
                ->exists();

            if ($conflict) {
                $this->line(sprintf(
                    '    pivot %d (batch %d, attach %d): drop (conflict)',
                    $item->id,
                    $item->batch_id,
                    $item->attachment_index,
                ));
                if ($apply) {
                    $item->delete();
                }
                $dropped++;
            } else {
                $this->line(sprintf(
                    '    pivot %d (batch %d, attach %d): repoint #%d → #%d',
                    $item->id,
                    $item->batch_id,
                    $item->attachment_index,
                    $dupId,
                    $survivorId,
                ));
                if ($apply) {
                    DB::table('auto_receipt_email_batch_items')
                        ->where('id', $item->id)
                        ->update(['expense_receipt_id' => $survivorId, 'updated_at' => now()]);
                }
                $repointed++;
            }
        }

        return [$repointed, $dropped];
    }

    /* --------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

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
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array{0: float, 1: int}  [sum, count]
     */
    private function itemsSum(mixed $items): array
    {
        if (! is_array($items)) {
            return [0.0, 0];
        }
        $sum = 0.0;
        $count = 0;
        foreach ($items as $item) {
            if (! is_array($item) || ! array_key_exists('TotalPrice', $item)) {
                continue;
            }
            $price = $item['TotalPrice'];
            if ($price === null || $price === '' || ! is_numeric($price)) {
                continue;
            }
            $sum += (float) $price;
            $count++;
        }

        return [round($sum, 2), $count];
    }
}
