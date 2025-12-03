<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SplitCheckTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checks:split-transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and split all checks with multiple transactions into separate checks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Specific checks that need splitting
        $checksToProcess = [
            ['check_id' => 3604, 'expense_id' => 25829],
            // ['check_id' => 3015, 'expense_id' => 22378], // This check is legitimate - 2 transactions make up 1 check payment
            ['check_id' => 3027, 'expense_id' => 22484],
            ['check_id' => 3009, 'expense_id' => 22381],
        ];

        $this->info("Processing " . count($checksToProcess) . " check(s) that need splitting.");
        $this->newLine();

        $successCount = 0;
        $errorCount = 0;

        foreach ($checksToProcess as $item) {
            $oldCheck = Check::find($item['check_id']);
            $expense = Expense::find($item['expense_id']);

            if (!$oldCheck) {
                $this->warn("Check #{$item['check_id']} not found or already deleted. Skipping...");
                $errorCount++;
                continue;
            }

            if (!$expense) {
                $this->warn("Expense #{$item['expense_id']} not found. Skipping check #{$item['check_id']}...");
                $errorCount++;
                continue;
            }

            $transactions = Transaction::where('check_id', $item['check_id'])->get();

            if ($transactions->count() <= 1) {
                $this->warn("Check #{$item['check_id']} has {$transactions->count()} transaction(s). Skipping...");
                continue;
            }

            $this->info("Processing Check #{$oldCheck->id} - " . money($oldCheck->amount) . " ({$oldCheck->check_type})");
            $this->info("  Expense: #{$expense->id} - " . money($expense->amount));
            $this->info("  Transactions: {$transactions->count()}");

            DB::beginTransaction();

            try {
                $newChecks = [];
                
                foreach ($transactions as $transaction) {
                    // Create new check matching the transaction amount
                    $newCheck = Check::create([
                        'amount' => $transaction->amount,
                        'date' => $oldCheck->date,
                        'check_type' => $oldCheck->check_type,
                        'check_number' => $oldCheck->check_number,
                        'bank_account_id' => $oldCheck->bank_account_id,
                        'vendor_id' => $oldCheck->vendor_id,
                        'belongs_to_vendor_id' => $oldCheck->belongs_to_vendor_id,
                        'created_by_user_id' => $oldCheck->created_by_user_id,
                        'user_id' => $oldCheck->user_id,
                    ]);
                    
                    // Reassign transaction to new check
                    $transaction->check_id = $newCheck->id;
                    $transaction->save();
                    
                    $newChecks[] = $newCheck->id;
                }

                // Link all new checks to the expense via pivot table
                foreach ($newChecks as $newCheckId) {
                    DB::table('check_expense')->insertOrIgnore([
                        'check_id' => $newCheckId,
                        'expense_id' => $expense->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Remove check_id from expense if it was using the old check
                if ($expense->check_id == $oldCheck->id) {
                    $expense->check_id = null;
                    $expense->save();
                }

                // Soft delete the old check
                $oldCheck->delete();

                DB::commit();

                $this->info("  ✓ Split into " . count($newChecks) . " new checks: " . implode(', ', array_map(fn($id) => "#{$id}", $newChecks)));
                $this->newLine();
                $successCount++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("  ✗ Error splitting check #{$oldCheck->id}: " . $e->getMessage());
                $this->newLine();
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("========================================");
        $this->info("Split {$successCount} check(s) successfully");
        if ($errorCount > 0) {
            $this->warn("Failed to split {$errorCount} check(s)");
        }

        return 0;
    }
}
