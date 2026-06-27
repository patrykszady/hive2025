<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTransactionNameDescriptionConflict extends Command
{
    protected $signature = 'transactions:fix-name-desc-conflict
        {--transaction-id=29060 : Transaction id to repair}
        {--expense-id=27214 : Expected linked expense id}
        {--dry-run : Show what would be changed without writing}';

    protected $description = 'Fix a bad transaction match where merchant_name conflicts with merchant_description';

    public function handle(): int
    {
        $transactionId = (int) $this->option('transaction-id');
        $expectedExpenseId = (int) $this->option('expense-id');
        $dryRun = (bool) $this->option('dry-run');

        $transaction = Transaction::withoutGlobalScopes()->find($transactionId);

        if (! $transaction) {
            $this->error("Transaction {$transactionId} not found.");

            return self::FAILURE;
        }

        $currentExpenseId = $transaction->expense_id !== null ? (int) $transaction->expense_id : null;

        $this->table(
            ['Field', 'Value'],
            [
                ['transaction_id', (string) $transaction->id],
                ['current_vendor_id', (string) ($transaction->vendor_id ?? 'NULL')],
                ['current_expense_id', (string) ($currentExpenseId ?? 'NULL')],
                ['expected_expense_id', (string) $expectedExpenseId],
                ['plaid_merchant_name', (string) ($transaction->plaid_merchant_name ?? '')],
                ['plaid_merchant_description', (string) ($transaction->plaid_merchant_description ?? '')],
            ]
        );

        if ($currentExpenseId !== null && $currentExpenseId !== $expectedExpenseId) {
            $this->warn("Transaction {$transactionId} is linked to expense {$currentExpenseId}, not {$expectedExpenseId}. No changes applied.");

            return self::SUCCESS;
        }

        $pivotRows = DB::table('expense_transaction')
            ->where('transaction_id', $transaction->id)
            ->where('expense_id', $expectedExpenseId)
            ->count();

        if ($dryRun) {
            $this->newLine();
            $this->line("[DRY RUN] Would set transaction {$transactionId} vendor_id=NULL, expense_id=NULL.");
            $this->line("[DRY RUN] Would delete {$pivotRows} row(s) from expense_transaction for expense {$expectedExpenseId}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($transaction, $expectedExpenseId): void {
            $transaction->vendor_id = null;
            $transaction->expense_id = null;
            $transaction->saveQuietly();

            DB::table('expense_transaction')
                ->where('transaction_id', $transaction->id)
                ->where('expense_id', $expectedExpenseId)
                ->delete();
        });

        $this->newLine();
        $this->info("Updated transaction {$transactionId}: vendor_id and expense_id cleared.");
        $this->info("Deleted matching pivot rows for expense {$expectedExpenseId}.");

        return self::SUCCESS;
    }
}
