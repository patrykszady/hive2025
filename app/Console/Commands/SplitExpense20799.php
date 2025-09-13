<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SplitExpense20799 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'split:expense-20799 {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Split expense 20799 into two separate expenses with correct transactions and receipts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('=== Splitting Expense 20799 ===');
        
        // Get current expense
        $expense = Expense::find(20799);
        if (!$expense) {
            $this->error('Expense 20799 not found!');
            return 1;
        }

        // Get transactions
        $transactions = $expense->transactions;
        $receipts = DB::table('expense_receipts_data')->where('expense_id', 20799)->get();

        $this->info('Current state:');
        $this->line("Expense 20799: \${$expense->amount} - {$expense->vendor->business_name}");
        $this->line("Transactions: {$transactions->count()}");
        foreach ($transactions as $t) {
            $this->line("  - {$t->transaction_date}: \${$t->amount} ({$t->plaid_transaction_id})");
        }
        $this->line("Receipts: {$receipts->count()}");
        foreach ($receipts as $r) {
            $receiptData = json_decode($r->receipt_items, true);
            $date = $receiptData['transaction_date'] ?? 'unknown';
            $item = $receiptData['items'][0]['Description'] ?? 'unknown';
            $this->line("  - {$date}: \${$receiptData['total']} ({$item})");
        }

        $this->info("\nPlan:");
        $this->line("• Keep expense 20799 for: 2024-01-17 receipt + 2024-01-19 transaction (WHITE CLAW)");
        $this->line("• Create new expense for: 2024-01-18 receipt + 2024-01-20 transaction (TRULY TROPICAL)");

        if ($dryRun) {
            $this->warn("\n[DRY RUN] No changes made. Remove --dry-run to apply.");
            return 0;
        }

        if (!$this->confirm("\nProceed with split?")) {
            $this->info('Cancelled.');
            return 0;
        }

        // Create new expense (duplicate of original)
        $newExpense = $expense->replicate();
        $newExpense->created_at = now();
        $newExpense->updated_at = now();
        $newExpense->date = '2024-01-18'; // Receipt date for TRULY TROPICAL
        $newExpense->save();

        $this->info("✓ Created new expense ID: {$newExpense->id}");

        // Move the 2024-01-20 transaction (TRULY TROPICAL) to new expense
        $tropicalTransaction = $transactions->where('transaction_date', '2024-01-20')->first();
        if ($tropicalTransaction) {
            $tropicalTransaction->expense_id = $newExpense->id;
            $tropicalTransaction->save();
            $this->info("✓ Moved 2024-01-20 transaction to expense {$newExpense->id}");
        }

        // Move the 2024-01-18 receipt (TRULY TROPICAL) to new expense
        $tropicalReceipt = $receipts->first(function($r) {
            $data = json_decode($r->receipt_items, true);
            return $data['transaction_date'] === '2024-01-18';
        });
        
        if ($tropicalReceipt) {
            DB::table('expense_receipts_data')
                ->where('id', $tropicalReceipt->id)
                ->update([
                    'expense_id' => $newExpense->id,
                    'updated_at' => now()
                ]);
            $this->info("✓ Moved 2024-01-18 receipt to expense {$newExpense->id}");
        }

        // Update original expense date to match WHITE CLAW receipt
        $expense->date = '2024-01-17';
        $expense->save();
        $this->info("✓ Updated expense 20799 date to 2024-01-17");

        $this->info("\n🎉 Split completed!");
        $this->info("Expense 20799: 2024-01-17 WHITE CLAW + 2024-01-19 transaction");
        $this->info("Expense {$newExpense->id}: 2024-01-18 TRULY TROPICAL + 2024-01-20 transaction");
        
        return 0;
    }
}
