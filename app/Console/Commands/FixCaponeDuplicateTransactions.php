<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;

class FixCaponeDuplicateTransactions extends Command
{
    protected $signature = 'fix:capone-duplicate-transactions
                            {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Clean up duplicate Cap One 4060 transactions from the 8338→4060 account number transition';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        // Duplicate 4060 transactions that mirror 8338 transactions
        $duplicateTxnIds = [25967, 25977, 25978];
        // Expense created from duplicate txn#25978
        $duplicateExpenseId = 24557;
        // Expense that was wrongly paired with the duplicate (Citibank debit side)
        $orphanedExpenseId = 24558;

        $this->info('Step 1: Soft-delete duplicate 4060 transactions');
        foreach ($duplicateTxnIds as $txnId) {
            $txn = Transaction::withoutGlobalScopes()->find($txnId);

            if (! $txn) {
                $this->error("  Transaction #{$txnId} not found.");
                continue;
            }

            if ($txn->trashed()) {
                $this->line("  Transaction #{$txnId} already soft-deleted. Skipping.");
                continue;
            }

            $this->line("  Soft-deleting txn#{$txnId} (acct:{$txn->bank_account_id}, {$txn->transaction_date}, \${$txn->amount})");

            if (! $dryRun) {
                $txn->delete();
            }
        }

        $this->info('Step 2: Soft-delete duplicate expense #' . $duplicateExpenseId);
        $dupExp = Expense::withoutGlobalScopes()->find($duplicateExpenseId);

        if ($dupExp) {
            if ($dupExp->trashed()) {
                $this->line("  Expense #{$duplicateExpenseId} already soft-deleted. Skipping.");
            } else {
                $this->line("  Soft-deleting exp#{$duplicateExpenseId} ({$dupExp->date}, \${$dupExp->amount})");

                if (! $dryRun) {
                    $dupExp->delete();
                }
            }
        } else {
            $this->error("  Expense #{$duplicateExpenseId} not found.");
        }

        $this->info('Step 3: Unlink orphaned expense #' . $orphanedExpenseId . ' from deleted parent');
        $orphanExp = Expense::withoutGlobalScopes()->find($orphanedExpenseId);

        if ($orphanExp) {
            if ($orphanExp->parent_expense_id === $duplicateExpenseId) {
                $this->line("  Removing parent_expense_id={$duplicateExpenseId} from exp#{$orphanedExpenseId}");

                if (! $dryRun) {
                    $orphanExp->update(['parent_expense_id' => null]);
                }
            } elseif ($orphanExp->parent_expense_id === null) {
                $this->line("  Expense #{$orphanedExpenseId} already unlinked. Skipping.");
            } else {
                $this->warn("  Expense #{$orphanedExpenseId} has unexpected parent_expense_id={$orphanExp->parent_expense_id}. Skipping.");
            }
        } else {
            $this->error("  Expense #{$orphanedExpenseId} not found.");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN complete. Run without --dry-run to apply changes.');
        } else {
            $this->newLine();
            $this->info('Done. Cleaned up 3 duplicate transactions, 1 duplicate expense, and 1 orphaned link.');
        }

        return self::SUCCESS;
    }
}
