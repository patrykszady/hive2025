<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

class FixReceiptTotals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'receipts:fix-totals {--dry-run : Preview changes without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix receipts where subtotal equals total but tax exists (should be total = subtotal + tax)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY RUN mode - no changes will be saved');
        }

        // Find receipts where subtotal == total but tax > 0, excluding deleted expenses and negative subtotals
        $receipts = ExpenseReceipts::withoutGlobalScopes()
            ->with('expense')
            ->whereHas('expense', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->whereNotNull('receipt_items->subtotal')
            ->whereNotNull('receipt_items->total')
            ->whereNotNull('receipt_items->total_tax')
            ->whereRaw('JSON_EXTRACT(receipt_items, "$.subtotal") = JSON_EXTRACT(receipt_items, "$.total")')
            ->whereRaw('CAST(JSON_EXTRACT(receipt_items, "$.total_tax") AS DECIMAL(10,2)) > 0')
            ->whereRaw('CAST(JSON_EXTRACT(receipt_items, "$.subtotal") AS DECIMAL(10,2)) >= 0')
            ->get();

        if ($receipts->isEmpty()) {
            $this->info('No receipts found with this issue.');
            return Command::SUCCESS;
        }

        $this->info("Found {$receipts->count()} receipt(s) to fix.");
        $this->newLine();

        // Show all receipts first
        foreach ($receipts as $receipt) {
            $items = $receipt->receipt_items;
            $subtotal = (float) $items['subtotal'];
            $total = (float) $items['total'];
            $tax = (float) $items['total_tax'];
            $expenseAmount = (float) $receipt->expense->amount;
            
            // Determine which field is correct by comparing to expense amount
            $totalMatchesExpense = abs($total - $expenseAmount) < 0.01;
            $subtotalPlusTaxMatchesExpense = abs(($subtotal + $tax) - $expenseAmount) < 0.01;

            $this->line("Receipt ID: {$receipt->id} (Expense ID: {$receipt->expense_id}, Amount: \${$expenseAmount})");
            $this->line("  Current: subtotal={$subtotal}, total={$total}, tax={$tax}");
            
            if ($totalMatchesExpense) {
                // Total is correct, fix subtotal
                $correctedSubtotal = $total - $tax;
                $this->line("  Fixed:   subtotal={$correctedSubtotal} (total is correct)");
            } else {
                // Subtotal is correct, fix total
                $correctedTotal = $subtotal + $tax;
                $this->line("  Fixed:   total={$correctedTotal} (subtotal is correct)");
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would fix {$receipts->count()} receipt(s)");
            $this->info('Run without --dry-run to apply changes');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('Do you want to proceed with fixing these receipts?', true)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $fixed = 0;
        $bar = $this->output->createProgressBar($receipts->count());
        $bar->start();

        foreach ($receipts as $receipt) {
            $items = $receipt->receipt_items;
            $subtotal = (float) $items['subtotal'];
            $total = (float) $items['total'];
            $tax = (float) $items['total_tax'];
            $expenseAmount = (float) $receipt->expense->amount;

            if ($subtotal == $total && $tax > 0) {
                // Determine which field is correct by comparing to expense amount
                $totalMatchesExpense = abs($total - $expenseAmount) < 0.01;
                
                if ($totalMatchesExpense) {
                    // Total is correct, fix subtotal
                    $items['subtotal'] = $total - $tax;
                } else {
                    // Subtotal is correct, fix total
                    $items['total'] = $subtotal + $tax;
                }
                
                $receipt->receipt_items = $items;
                $receipt->save();
                $fixed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully fixed {$fixed} receipt(s)");

        return Command::SUCCESS;
    }
}
