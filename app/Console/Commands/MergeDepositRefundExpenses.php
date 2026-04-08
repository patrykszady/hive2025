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

            // Only merge deposit+refund pairs (one positive, one negative)
            $positive = $expenses->filter(fn ($e) => $e->amount > 0);
            $negative = $expenses->filter(fn ($e) => $e->amount < 0);

            if ($positive->count() !== 1 || $negative->count() !== 1) {
                $this->warn("Skipping DEPOSIT NO# {$depositNo} — not a clean deposit+refund pair (pos:{$positive->count()}, neg:{$negative->count()})");
                $skipped++;

                continue;
            }

            $depositExpense = $positive->first();
            $refundExpense = $negative->first();
            $netAmount = round($depositExpense->amount + $refundExpense->amount, 2);

            $this->line('');
            $this->info("DEPOSIT NO# {$depositNo}");
            $this->line("  Keep:   E#{$depositExpense->id} \${$depositExpense->amount} {$depositExpense->date->format('Y-m-d')}");
            $this->line("  Remove: E#{$refundExpense->id} \${$refundExpense->amount} {$refundExpense->date->format('Y-m-d')}");
            $this->line("  Net amount: \${$netAmount}");

            if ($dryRun) {
                $merged++;

                continue;
            }

            DB::beginTransaction();
            try {
                // Move receipts from refund expense to deposit expense
                ExpenseReceipts::where('expense_id', $refundExpense->id)
                    ->update(['expense_id' => $depositExpense->id]);

                // Move transactions from refund expense to deposit expense
                Transaction::withoutGlobalScopes()
                    ->where('expense_id', $refundExpense->id)
                    ->update(['expense_id' => $depositExpense->id]);

                // Update the kept expense amount to the net
                $depositExpense->amount = $netAmount;
                $depositExpense->save();

                // Soft-delete the refund expense
                $refundExpense->delete();

                DB::commit();

                $this->line("  ✓ Merged → E#{$depositExpense->id} now \${$netAmount}");

                Log::info('Merged deposit+refund expenses', [
                    'deposit_number' => $depositNo,
                    'kept_expense_id' => $depositExpense->id,
                    'removed_expense_id' => $refundExpense->id,
                    'net_amount' => $netAmount,
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
