<?php

namespace App\Observers;

use App\Models\Expense;
use App\Jobs\UpdateVendorSearchIndex;

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

        // // If vendor changed, also update the old vendor's index
        // if ($expense->isDirty('vendor_id') && $expense->getOriginal('vendor_id')) {
        //     UpdateVendorSearchIndex::dispatch($expense->getOriginal('vendor_id'));
        // }
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
}
