<?php

namespace App\Observers;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Jobs\UpdateVendorSearchIndex;
use Illuminate\Support\Facades\Log;

class ExpenseObserver
{
    public function creating(Expense $expense): void {}

    /**
     * Handle the Expense "created" event.
     */
    public function created(Expense $expense): void
    {
        if ($expense->vendor_id) {
            UpdateVendorSearchIndex::dispatch($expense->vendor_id);
        }
        
        // Auto-create returned check/expense if this expense is linked to a check with a returned transaction
        if ($expense->check_id) {
            $this->handleReturnedCheckForExpense($expense);
        }
    }

    /**
     * Handle the Expense "updated" event.
     */
    public function updated(Expense $expense): void
    {
        // Update search index for current vendor
        if ($expense->vendor_id) {
            UpdateVendorSearchIndex::dispatch($expense->vendor_id);
        }

        // If vendor changed, also update the old vendor's index
        // if ($expense->isDirty('vendor_id') && $expense->getOriginal('vendor_id')) {
        //     UpdateVendorSearchIndex::dispatch($expense->getOriginal('vendor_id'));
        // }

        // If paid_by is set (regardless of whether it changed in this update), detach any directly-associated transactions
        if (! empty($expense->paid_by)) {
            // Only detach transactions linked via expense_id; check-attached txns are unaffected
            if ($expense->transactions()->exists()) {
                // Get transaction IDs before bulk update (bulk update bypasses Scout indexing)
                $transactionIds = $expense->transactions()->pluck('id')->toArray();

                $expense->transactions()->update(['expense_id' => null]);

                // Re-index transactions in Meilisearch after bulk update
                if (! empty($transactionIds)) {
                    Transaction::withoutGlobalScopes()
                        ->whereIn('id', $transactionIds)
                        ->searchable();
                }
            }
        }
        
        // Auto-create returned check/expense if check_id was just set
        if ($expense->isDirty('check_id') && $expense->check_id) {
            $this->handleReturnedCheckForExpense($expense);
        }
    }

    /**
     * Handle the Expense "deleted" event.
     */
    public function deleted(Expense $expense): void
    {
        if ($expense->vendor_id) {
            UpdateVendorSearchIndex::dispatch($expense->vendor_id);
        }

        //RECEIPTS
        $expense->receipts()->delete();
    }

    /**
     * Handle the Expense "restored" event.
     */
    public function restored(Expense $expense): void
    {
        if ($expense->vendor_id) {
            UpdateVendorSearchIndex::dispatch($expense->vendor_id);
        }
    }

    /**
     * Handle the Expense "force deleted" event.
     */
    public function forceDeleted(Expense $expense): void
    {
        if ($expense->vendor_id) {
            UpdateVendorSearchIndex::dispatch($expense->vendor_id);
        }
    }
    
    /**
     * When an expense is linked to a check, check if there's a "RETURNED CHECK" transaction
     * for the same check number. If so, auto-create the returned check and expense records.
     */
    protected function handleReturnedCheckForExpense(Expense $expense): void
    {
        $check = Check::withoutGlobalScopes()->find($expense->check_id);
        if (!$check || $check->check_type === 'Returned Check') {
            return; // Don't process if already a returned check
        }
        
        // Find the original transaction for this check
        $originalTransaction = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('check_id', $check->id)
            ->where('amount', '>', 0)
            ->first();
        
        if (!$originalTransaction) {
            return;
        }
        
        // Look for a returned check transaction with same check_number and bank
        $returnedTransaction = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('check_id') // Not yet linked
            ->where('check_number', $originalTransaction->check_number)
            ->where('bank_account_id', $originalTransaction->bank_account_id)
            ->where('plaid_merchant_description', 'LIKE', '%RETURNED%CHECK%')
            ->where('amount', '<', 0)
            ->first();
        
        if (!$returnedTransaction) {
            return; // No returned check transaction found
        }
        
        // Check if a returned check already exists for this check number
        $existingReturnedCheck = Check::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('check_type', 'Returned Check')
            ->where('check_number', $check->check_number)
            ->where('bank_account_id', $check->bank_account_id)
            ->first();
        
        if ($existingReturnedCheck) {
            return; // Already processed
        }
        
        // Create the returned check
        $returnedCheck = Check::create([
            'check_type' => 'Returned Check',
            'check_number' => $check->check_number,
            'date' => $returnedTransaction->transaction_date,
            'amount' => $returnedTransaction->amount,
            'bank_account_id' => $check->bank_account_id,
            'vendor_id' => $check->vendor_id,
            'belongs_to_vendor_id' => $check->belongs_to_vendor_id,
            'created_by_user_id' => 0,
        ]);
        
        // Link returned transaction to the new check
        $returnedTransaction->check_id = $returnedCheck->id;
        $returnedTransaction->vendor_id = $check->vendor_id;
        $returnedTransaction->save();
        
        // Find or create returned expense
        $returnedExpense = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('amount', $returnedTransaction->amount)
            ->where('vendor_id', $expense->vendor_id)
            ->whereBetween('date', [
                $returnedTransaction->transaction_date->copy()->subDays(3)->format('Y-m-d'),
                $returnedTransaction->transaction_date->copy()->addDays(3)->format('Y-m-d'),
            ])
            ->first();
        
        if ($returnedExpense) {
            // Update existing expense
            $returnedExpense->check_id = $returnedCheck->id;
            $returnedExpense->parent_expense_id = $expense->id;
            $returnedExpense->save();
        } else {
            // Create new returned expense
            $returnedExpense = Expense::create([
                'date' => $returnedTransaction->transaction_date,
                'amount' => $returnedTransaction->amount,
                'distribution_id' => $expense->distribution_id,
                'vendor_id' => $expense->vendor_id,
                'check_id' => $returnedCheck->id,
                'category_id' => $expense->category_id,
                'parent_expense_id' => $expense->id,
                'belongs_to_vendor_id' => $expense->belongs_to_vendor_id,
                'created_by_user_id' => 0,
            ]);
        }
        
        // Link transaction to expense
        $returnedTransaction->expense_id = $returnedExpense->id;
        $returnedTransaction->save();
        
        Log::info('Auto-created returned check and expense from expense link', [
            'original_expense_id' => $expense->id,
            'original_check_id' => $check->id,
            'returned_check_id' => $returnedCheck->id,
            'returned_expense_id' => $returnedExpense->id,
            'returned_transaction_id' => $returnedTransaction->id,
        ]);
    }
}
