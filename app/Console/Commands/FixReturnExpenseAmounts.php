<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FixReturnExpenseAmounts extends Command
{
    protected $signature = 'expenses:fix-return-amounts {--dry-run : Show what would be updated without making changes} {--fix : Actually apply the fixes}';

    protected $description = 'Find and fix expenses created from return receipts where the negative sign was stripped from the amount';

    public function handle(): int
    {
        $dryRun = ! $this->option('fix');

        if ($dryRun) {
            $this->info('DRY RUN — showing affected expenses. Pass --fix to apply changes.');
        }

        $affected = $this->findAffectedExpenses();

        if ($affected->isEmpty()) {
            $this->info('No affected expenses found.');

            return self::SUCCESS;
        }

        $tableRows = [];

        foreach ($affected as $row) {
            $expense = $row['expense'];
            $receipt = $row['receipt'];
            $receiptItems = $receipt->receipt_items ?? [];
            $correctAmount = -abs($expense->amount);

            // Find a matching unlinked negative transaction.
            $candidateTransaction = $this->findMatchingTransaction($expense, $correctAmount);

            // Check for a duplicate negative expense already existing.
            $duplicateExpense = Expense::where('belongs_to_vendor_id', $expense->belongs_to_vendor_id)
                ->where('amount', $correctAmount)
                ->whereNull('deleted_at')
                ->where('id', '!=', $expense->id)
                ->whereBetween('date', [
                    Carbon::parse($expense->date)->subDays(3)->toDateString(),
                    Carbon::parse($expense->date)->addDays(3)->toDateString(),
                ])
                ->first();

            $status = 'will negate';
            if ($duplicateExpense) {
                $status = "duplicate of #{$duplicateExpense->id}";
            }

            $tableRows[] = [
                'expense_id' => $expense->id,
                'date' => $expense->date instanceof Carbon ? $expense->date->format('Y-m-d') : $expense->date,
                'vendor' => substr($expense->vendor->business_name ?? '-', 0, 25),
                'current_amount' => '$' . number_format((float) $expense->amount, 2),
                'correct_amount' => '$' . number_format($correctAmount, 2),
                'receipt_subtotal' => $receiptItems['subtotal'] ?? '-',
                'receipt_tax' => $receiptItems['total_tax'] ?? '-',
                'candidate_txn' => $candidateTransaction ? "#{$candidateTransaction->id} ({$candidateTransaction->amount})" : 'none',
                'status' => $status,
            ];

            if (! $dryRun) {
                if ($duplicateExpense) {
                    $this->warn("  Expense #{$expense->id} has duplicate #{$duplicateExpense->id} — soft-deleting the positive duplicate.");
                    $expense->delete();
                } else {
                    $expense->amount = $correctAmount;
                    $expense->save();
                    $this->info("  Expense #{$expense->id} amount changed to {$correctAmount}");

                    // Fix the stored receipt total too.
                    $receiptItems['total'] = $correctAmount;
                    $receipt->receipt_items = $receiptItems;
                    $receipt->save();

                    // Link the matching transaction if found.
                    if ($candidateTransaction) {
                        $hasDirectLink = Transaction::where('expense_id', $expense->id)->exists();
                        $hasPivotLink = $expense->sharedTransactions()->where('transactions.id', $candidateTransaction->id)->exists();

                        if (! $hasDirectLink && ! $hasPivotLink) {
                            $candidateTransaction->expense_id = $expense->id;
                            $candidateTransaction->save();
                            $this->info("    Linked transaction #{$candidateTransaction->id} ({$candidateTransaction->amount})");
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Expense ID', 'Date', 'Vendor', 'Current $', 'Correct $', 'Subtotal', 'Tax', 'Candidate Txn', 'Status'],
            $tableRows
        );

        $this->newLine();
        $this->info("Total affected: " . count($tableRows));

        if ($dryRun) {
            $this->warn('Run with --fix to apply changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Find expenses where receipt data indicates a return (negative subtotal + negative tax)
     * but the expense amount was saved as positive.
     *
     * @return \Illuminate\Support\Collection<int, array{expense: Expense, receipt: ExpenseReceipts}>
     */
    private function findAffectedExpenses(): \Illuminate\Support\Collection
    {
        $receipts = ExpenseReceipts::with(['expense.vendor'])
            ->whereNotNull('receipt_items')
            ->whereHas('expense', function ($query) {
                $query->where('amount', '>', 0)->whereNull('deleted_at');
            })
            ->get();

        return $receipts->filter(function (ExpenseReceipts $receipt) {
            $items = $receipt->receipt_items;
            if (! is_array($items)) {
                return false;
            }

            $subtotal = $items['subtotal'] ?? null;
            $tax = $items['total_tax'] ?? null;

            if (! is_numeric($subtotal) || ! is_numeric($tax)) {
                return false;
            }

            // Both subtotal and tax are negative (or zero tax) = pure return receipt.
            return (float) $subtotal < 0 && (float) $tax <= 0;
        })->map(function (ExpenseReceipts $receipt) {
            return ['expense' => $receipt->expense, 'receipt' => $receipt];
        })->values();
    }

    /**
     * Find a matching unlinked negative transaction for this expense.
     */
    private function findMatchingTransaction(Expense $expense, float $correctAmount): ?Transaction
    {
        $expenseDate = Carbon::parse($expense->date);

        return Transaction::whereNull('expense_id')
            ->whereNull('check_number')
            ->whereNull('deposit')
            ->where('amount', $correctAmount)
            ->whereBetween('transaction_date', [
                $expenseDate->copy()->subDays(7)->toDateString(),
                $expenseDate->copy()->addDays(7)->toDateString(),
            ])
            ->orderByRaw('ABS(DATEDIFF(transaction_date, ?))', [$expenseDate->toDateString()])
            ->first();
    }
}
