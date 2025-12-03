<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidatePartialExpenses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expenses:consolidate-partials';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and consolidate partial expenses that sum to a receipt total';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Specific expense to consolidate: 25962 with partials 25830 and 25848
        $consolidations = [
            [
                'main_expense_id' => 25962,
                'partial_expense_ids' => [25830, 25848],
            ],
        ];

        $this->info("Processing " . count($consolidations) . " expense consolidation(s).");
        $this->newLine();

        $successCount = 0;
        $errorCount = 0;

        foreach ($consolidations as $consolidation) {
            $mainExpense = Expense::find($consolidation['main_expense_id']);
            if (!$mainExpense) {
                $this->warn("Main expense #{$consolidation['main_expense_id']} not found. Skipping...");
                $errorCount++;
                continue;
            }

            $this->info("Main Expense: #{$mainExpense->id} - " . money($mainExpense->amount));
            $this->info("Invoice: {$mainExpense->invoice}");
            $this->info("Partials: " . count($consolidation['partial_expense_ids']) . " expense(s)");
            $this->newLine();

            DB::beginTransaction();

            try {
                $checkIds = [];
                $totalPartialAmount = 0;
                
                foreach ($consolidation['partial_expense_ids'] as $partialId) {
                    $partialExpense = Expense::find($partialId);
                    if (!$partialExpense) {
                        $this->warn("  Partial expense #{$partialId} not found. Skipping...");
                        continue;
                    }

                    $this->info("  Processing #{$partialExpense->id} - " . money($partialExpense->amount));
                    $totalPartialAmount += $partialExpense->amount;

                    // Collect check_id if exists
                    if ($partialExpense->check_id) {
                        $checkIds[] = $partialExpense->check_id;
                        $this->info("    - Found check: {$partialExpense->check_id}");
                    }

                    // Transfer transactions
                    $transactionCount = Transaction::where('expense_id', $partialExpense->id)->count();
                    if ($transactionCount > 0) {
                        Transaction::where('expense_id', $partialExpense->id)
                            ->update(['expense_id' => $mainExpense->id]);
                        $this->info("    - Transferred {$transactionCount} transaction(s)");
                    }

                    // Soft delete the partial expense
                    $partialExpense->delete();
                    $this->info("    - Soft deleted");
                }

                // Verify amounts match
                $this->info("  Total partial amounts: " . money($totalPartialAmount));
                $this->info("  Main expense amount: " . money($mainExpense->amount));
                
                if (abs($totalPartialAmount - $mainExpense->amount) > 0.01) {
                    $this->warn("  Warning: Amounts don't match exactly!");
                }

                // Link checks to main expense via pivot table
                if (!empty($checkIds)) {
                    $checkIds = array_unique(array_filter($checkIds));
                    foreach ($checkIds as $checkId) {
                        DB::table('check_expense')->insertOrIgnore([
                            'check_id' => $checkId,
                            'expense_id' => $mainExpense->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $this->info("  ✓ Linked " . count($checkIds) . " check(s) to main expense");
                }

                DB::commit();

                $this->info("  ✓ Consolidation complete for expense #{$mainExpense->id}");
                $this->newLine();
                $successCount++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("  ✗ Error consolidating expense #{$mainExpense->id}: " . $e->getMessage());
                $this->newLine();
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("========================================");
        $this->info("Consolidated {$successCount} expense(s) successfully");
        if ($errorCount > 0) {
            $this->warn("Failed to consolidate {$errorCount} expense(s)");
        }

        return 0;
    }
}
