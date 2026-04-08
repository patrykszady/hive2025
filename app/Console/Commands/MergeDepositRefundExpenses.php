<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeDepositRefundExpenses extends Command
{
    protected $signature = 'app:merge-deposit-refund-expenses {--dry-run : Preview changes without modifying data}';

    protected $description = 'Merge Home Depot deposit+refund expense pairs that share a DEPOSIT NO# into a single expense';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $receipts = ExpenseReceipts::whereNotNull('receipt_html')
            ->where('receipt_html', 'LIKE', '%DEPOSIT NO#%')
            ->get();

        $byDeposit = collect();
        foreach ($receipts as $r) {
            if (preg_match('/DEPOSIT NO#\s*(\S+)/', $r->receipt_html, $m)) {
                $byDeposit->push([
                    'deposit' => $m[1],
                    'receipt_id' => $r->id,
                    'expense_id' => $r->expense_id,
                ]);
            }
        }

        $groups = $byDeposit->groupBy('deposit')->filter(fn ($g) => $g->pluck('expense_id')->unique()->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No expense pairs to merge.');

            return self::SUCCESS;
        }

        $merged = 0;
        $skipped = 0;

        foreach ($groups as $depositNo => $items) {
            $expenseIds = $items->pluck('expense_id')->unique()->values();
            $expenses = $expenseIds->map(fn ($id) => Expense::withoutGlobalScopes()->find($id))->filter();

            // Skip already-trashed expenses
            $expenses = $expenses->filter(fn ($e) => ! $e->trashed());
            if ($expenses->count() < 2) {
                continue;
            }

            // Determine merge strategy
            $positive = $expenses->filter(fn ($e) => $e->amount > 0);
            $negative = $expenses->filter(fn ($e) => $e->amount < 0);

            if ($positive->count() === 1 && $negative->count() === 1) {
                // Pattern 1: one deposit + one refund → net amount
                $keepExpense = $positive->first();
                $removeExpense = $negative->first();
                $finalAmount = round($keepExpense->amount + $removeExpense->amount, 2);
            } elseif ($positive->count() === 2 && $negative->count() === 0) {
                // Pattern 2: two positives — later receipt is the final amount (includes refund)
                $sorted = $positive->sortBy('date')->values();
                $removeExpense = $sorted->first(); // earlier deposit receipt
                $keepExpense = $sorted->last();     // later final receipt with correct amount
                $finalAmount = $keepExpense->amount;
            } else {
                $this->warn("Skipping DEPOSIT NO# {$depositNo} — unexpected pattern (pos:{$positive->count()}, neg:{$negative->count()})");
                $skipped++;

                continue;
            }

            $this->line('');
            $this->info("DEPOSIT NO# {$depositNo}");
            $this->line("  Keep:   E#{$keepExpense->id} \${$keepExpense->amount} {$keepExpense->date->format('Y-m-d')}");
            $this->line("  Remove: E#{$removeExpense->id} \${$removeExpense->amount} {$removeExpense->date->format('Y-m-d')}");
            $this->line("  Final amount: \${$finalAmount}");

            if ($dryRun) {
                $merged++;

                continue;
            }

            DB::beginTransaction();
            try {
                // Move receipts from removed expense to kept expense
                ExpenseReceipts::where('expense_id', $removeExpense->id)
                    ->update(['expense_id' => $keepExpense->id]);

                // Move transactions from removed expense to kept expense
                Transaction::withoutGlobalScopes()
                    ->where('expense_id', $removeExpense->id)
                    ->update(['expense_id' => $keepExpense->id]);

                // Use direct DB updates to bypass observers that may revert changes
                DB::table('expenses')->where('id', $keepExpense->id)->update([
                    'amount' => $finalAmount,
                    'parent_expense_id' => null,
                ]);

                // Clear parent_expense_id on removed expense and soft-delete
                DB::table('expenses')->where('id', $removeExpense->id)->update([
                    'parent_expense_id' => null,
                    'deleted_at' => now(),
                ]);

                DB::commit();

                $this->line("  ✓ Merged → E#{$keepExpense->id} now \${$finalAmount}");

                Log::info('Merged deposit+refund expenses', [
                    'deposit_number' => $depositNo,
                    'kept_expense_id' => $keepExpense->id,
                    'removed_expense_id' => $removeExpense->id,
                    'final_amount' => $finalAmount,
                ]);

                $merged++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Done. Merged: {$merged}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
