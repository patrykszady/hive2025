<?php

namespace App\Console\Commands;

use App\Jobs\BackfillReceiptHandwrittenNoteJob;
use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: combine same-purchase receipt rows created before the
 * supplement-precedence logic existed (e-receipt + paper scan of the same
 * purchase attached as full peers). Mirrors saveExpenseReceipt exactly:
 * matchesSamePurchase pairs rows, itemsSupersede picks the keeper (ties keep
 * the OLDER row, same as the incumbent-wins rule), losers become notes-only
 * supplements via demoteToSupplementOf — non-destructive, their items move
 * to supplanted_items and a JSON snapshot is written besides.
 *
 * Idempotent: supplements are excluded from pairing, so a second run finds
 * nothing. Invoked once per environment by the accompanying migration.
 */
class CombineSamePurchaseReceipts extends Command
{
    protected $signature = 'receipts:combine-same-purchase {--dry-run : Report what would combine without changing anything}';

    protected $description = 'Demote duplicate same-purchase receipt captures to notes-only supplements';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // The pairing query leans on MySQL's JSON functions; SQLite (tests)
        // spells them differently and has nothing to combine anyway.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->warn('receipts:combine-same-purchase requires MySQL — nothing done.');

            return self::SUCCESS;
        }

        $candidateExpenseIds = DB::table('expense_receipts_data as a')
            ->join('expense_receipts_data as b', function ($join) {
                $join->on('b.expense_id', '=', 'a.expense_id')->on('b.id', '>', 'a.id');
            })
            ->whereNull('a.deleted_at')->whereNull('b.deleted_at')
            ->where('a.is_material_order', 0)->where('b.is_material_order', 0)
            ->whereNull(DB::raw("JSON_EXTRACT(a.receipt_items,'$.supplement_of_receipt_id')"))
            ->whereNull(DB::raw("JSON_EXTRACT(b.receipt_items,'$.supplement_of_receipt_id')"))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(a.receipt_items,'$.total')) = JSON_UNQUOTE(JSON_EXTRACT(b.receipt_items,'$.total'))")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(a.receipt_items,'$.transaction_date')) = JSON_UNQUOTE(JSON_EXTRACT(b.receipt_items,'$.transaction_date'))")
            ->distinct()
            ->pluck('a.expense_id');

        $snapshot = [];
        $demoted = 0;
        $expensesTouched = 0;
        $backfillsQueued = 0;

        foreach ($candidateExpenseIds as $expenseId) {
            $rows = ExpenseReceipts::query()
                ->where('expense_id', $expenseId)
                ->where('is_material_order', false)
                ->orderBy('id')
                ->get()
                ->filter(fn (ExpenseReceipts $row) => ! $row->isSupplement())
                ->values();

            if ($rows->count() < 2) {
                continue;
            }

            // Group rows describing the same purchase (total + date + PO).
            $groups = [];
            foreach ($rows as $row) {
                foreach ($groups as &$group) {
                    if (ExpenseReceipts::matchesSamePurchase($group[0]->receipt_items ?? [], $row->receipt_items ?? [])) {
                        $group[] = $row;
                        continue 2;
                    }
                }
                unset($group);
                $groups[] = [$row];
            }
            unset($group);

            $touched = false;

            foreach ($groups as $group) {
                if (count($group) < 2) {
                    continue;
                }

                $keeper = $group[0];
                foreach ($group as $row) {
                    if ($row->id !== $keeper->id && ExpenseReceipts::itemsSupersede($row->receipt_items ?? [], $keeper->receipt_items ?? [])) {
                        $keeper = $row;
                    }
                }

                foreach ($group as $row) {
                    if ($row->id === $keeper->id) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("would demote receipt {$row->id} under {$keeper->id} (expense {$expenseId})");
                        $demoted++;
                        $touched = true;
                        continue;
                    }

                    $snapshot[] = ['id' => $row->id, 'expense_id' => $expenseId, 'receipt_items' => $row->receipt_items];
                    $row->demoteToSupplementOf($keeper);
                    $demoted++;
                    $touched = true;

                    // Notes are the only data a supplement contributes — give
                    // empty ones a fresh OCR pass on the background queue.
                    if (empty(($row->receipt_items ?? [])['handwritten_notes'] ?? [])) {
                        BackfillReceiptHandwrittenNoteJob::dispatch($row->id, onlyNew: true);
                        $backfillsQueued++;
                    }
                }
            }

            if ($touched) {
                $expensesTouched++;
            }
        }

        if (! $dryRun && $snapshot !== []) {
            $snapshotPath = storage_path('app/same-purchase-combine-' . now()->format('Ymd-His') . '.json');
            file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("snapshot: {$snapshotPath}");
        }

        $verb = $dryRun ? 'would demote' : 'demoted';
        $this->info("expenses touched: {$expensesTouched}; rows {$verb}: {$demoted}; note backfills queued: {$backfillsQueued}");

        return self::SUCCESS;
    }
}
