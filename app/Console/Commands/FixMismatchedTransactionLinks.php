<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class FixMismatchedTransactionLinks extends Command
{
    protected $signature = 'expenses:fix-mismatched-links
        {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'One-time fix for known mismatched expense↔transaction links (March 2026 audit)';

    /**
     * Fixes identified during manual audit:
     *
     * Expense 25972 (-$144.03 Menards) had 4 wrong transactions:
     *   - T.27676 ($41.25)  → should link to expense 25988 ($41.25 Menards)
     *   - T.27692 ($9.32)   → should be unlinked (positive on negative expense)
     *   - T.27565 (-$122.05)→ should be unlinked (no matching expense exists)
     *   - T.27642 (-$72.55) → should link to expense 25991 (-$72.55 Menards)
     *
     * Expense 25371 ($44.00 Home Depot) had 1 wrong transaction:
     *   - T.27142 (-$6.00)  → should be unlinked (opposite sign, wrong amount)
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $fixes = [
            ['transaction_id' => 27676, 'old_expense_id' => 25972, 'new_expense_id' => 25988, 'reason' => 'Relink $41.25 Menards to matching expense'],
            ['transaction_id' => 27692, 'old_expense_id' => 25972, 'new_expense_id' => null, 'reason' => 'Unlink $9.32 positive from negative expense'],
            ['transaction_id' => 27565, 'old_expense_id' => 25972, 'new_expense_id' => null, 'reason' => 'Unlink -$122.05 (no matching expense)'],
            ['transaction_id' => 27642, 'old_expense_id' => 25972, 'new_expense_id' => 25991, 'reason' => 'Relink -$72.55 Menards to matching expense'],
            ['transaction_id' => 27142, 'old_expense_id' => 25371, 'new_expense_id' => null, 'reason' => 'Unlink -$6.00 from $44.00 expense (sign mismatch)'],
        ];

        $this->table(
            ['Transaction', 'Old Expense', 'New Expense', 'Reason'],
            collect($fixes)->map(fn ($f) => [
                $f['transaction_id'],
                $f['old_expense_id'],
                $f['new_expense_id'] ?? 'NULL',
                $f['reason'],
            ])->toArray()
        );

        $this->newLine();

        $applied = 0;
        $skipped = 0;

        foreach ($fixes as $fix) {
            $transaction = Transaction::withoutGlobalScopes()->find($fix['transaction_id']);

            if (! $transaction) {
                $this->warn("Transaction {$fix['transaction_id']} not found — skipping.");
                $skipped++;

                continue;
            }

            if ((int) $transaction->expense_id !== (int) $fix['old_expense_id']) {
                $this->warn("Transaction {$fix['transaction_id']} expense_id is {$transaction->expense_id}, expected {$fix['old_expense_id']} — already fixed or changed. Skipping.");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("[DRY RUN] Would update transaction {$fix['transaction_id']}: expense_id {$fix['old_expense_id']} → " . ($fix['new_expense_id'] ?? 'NULL'));
            } else {
                $transaction->expense_id = $fix['new_expense_id'];
                $transaction->saveQuietly();
                $this->line("Fixed transaction {$fix['transaction_id']}: expense_id {$fix['old_expense_id']} → " . ($fix['new_expense_id'] ?? 'NULL'));
            }

            $applied++;
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Applied: {$applied}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
