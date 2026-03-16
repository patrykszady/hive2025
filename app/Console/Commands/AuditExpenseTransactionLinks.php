<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditExpenseTransactionLinks extends Command
{
    protected $signature = 'expenses:audit-transaction-links
        {--fix-sign-mismatches : Unlink transactions where a single transaction has opposite sign from its expense}
        {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and fix mismatched expense↔transaction links (amount/sign mismatches)';

    public function handle(): int
    {
        $fixSign = (bool) $this->option('fix-sign-mismatches');
        $dryRun = (bool) $this->option('dry-run');

        // Find all expenses where linked transaction sum ≠ expense amount
        $mismatches = DB::select("
            SELECT
                e.id AS expense_id,
                e.date AS expense_date,
                ROUND(e.amount, 2) AS expense_amount,
                ROUND(SUM(t.amount), 2) AS transaction_sum,
                COUNT(t.id) AS transaction_count,
                GROUP_CONCAT(t.id ORDER BY t.id) AS transaction_ids,
                GROUP_CONCAT(ROUND(t.amount, 2) ORDER BY t.id) AS transaction_amounts,
                v.business_name AS vendor_name
            FROM expenses e
            INNER JOIN transactions t ON t.expense_id = e.id AND t.deleted_at IS NULL
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.deleted_at IS NULL
            GROUP BY e.id, e.date, e.amount, v.business_name
            HAVING ROUND(SUM(t.amount), 2) != ROUND(e.amount, 2)
            ORDER BY e.date DESC
        ");

        if (empty($mismatches)) {
            $this->info('No mismatched expense↔transaction links found.');

            return self::SUCCESS;
        }

        $this->info(count($mismatches) . ' mismatched expense↔transaction links found.');
        $this->newLine();

        // Categorize mismatches
        $signMismatches = [];
        $amountMismatches = [];

        foreach ($mismatches as $row) {
            $transactionCount = (int) $row->transaction_count;
            $expenseAmount = (float) $row->expense_amount;
            $transactionSum = (float) $row->transaction_sum;

            // Single transaction with opposite sign = clear mismatch
            if ($transactionCount === 1 && $this->signsOpposite($expenseAmount, $transactionSum)) {
                $signMismatches[] = $row;
            } else {
                $amountMismatches[] = $row;
            }
        }

        // Display sign mismatches
        if (! empty($signMismatches)) {
            $this->error(count($signMismatches) . ' SIGN MISMATCHES (single transaction, opposite sign):');
            $this->table(
                ['Expense', 'Date', 'E.Amount', 'T.Amount', 'Vendor', 'Transaction'],
                collect($signMismatches)->map(fn ($r) => [
                    $r->expense_id,
                    $r->expense_date,
                    $r->expense_amount,
                    $r->transaction_sum,
                    $r->vendor_name,
                    $r->transaction_ids,
                ])->toArray()
            );
            $this->newLine();
        }

        // Display amount mismatches
        if (! empty($amountMismatches)) {
            $this->warn(count($amountMismatches) . ' AMOUNT MISMATCHES (sum ≠ expense):');
            $this->table(
                ['Expense', 'Date', 'E.Amount', 'T.Sum', 'T.Count', 'Diff', 'Vendor', 'Transactions'],
                collect($amountMismatches)->map(function ($r) {
                    $diff = round((float) $r->transaction_sum - (float) $r->expense_amount, 2);

                    return [
                        $r->expense_id,
                        $r->expense_date,
                        $r->expense_amount,
                        $r->transaction_sum,
                        $r->transaction_count,
                        ($diff >= 0 ? '+' : '') . $diff,
                        $r->vendor_name,
                        strlen($r->transaction_ids) > 30
                            ? substr($r->transaction_ids, 0, 27) . '...'
                            : $r->transaction_ids,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        // Fix sign mismatches if requested
        if ($fixSign && ! empty($signMismatches)) {
            $this->info('Fixing sign mismatches...');
            $fixed = 0;

            foreach ($signMismatches as $row) {
                $transactionId = (int) $row->transaction_ids;

                if ($dryRun) {
                    $this->line("  [DRY RUN] Would unlink transaction {$transactionId} from expense {$row->expense_id}");
                    $fixed++;

                    continue;
                }

                $transaction = Transaction::withoutGlobalScopes()->find($transactionId);

                if ($transaction) {
                    $transaction->expense_id = null;
                    $transaction->saveQuietly();
                    $fixed++;
                    $this->line("  Unlinked transaction {$transactionId} from expense {$row->expense_id}");
                }
            }

            $prefix = $dryRun ? '[DRY RUN] ' : '';
            $this->info("{$prefix}Fixed {$fixed} sign mismatches.");
        } elseif (! empty($signMismatches)) {
            $this->line('Use --fix-sign-mismatches to unlink opposite-sign single transaction links.');
        }

        return self::SUCCESS;
    }

    private function signsOpposite(float $a, float $b): bool
    {
        return ($a > 0 && $b < 0) || ($a < 0 && $b > 0);
    }
}
