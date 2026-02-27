<?php

namespace App\Observers;

use App\Models\Check;

class CheckObserver
{
    /**
     * Handle the Check "created" event.
     */
    public function created(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "updated" event.
     */
    public function updated(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "deleted" event.
     */
    public function deleted(Check $check): void
    {
        // Get IDs before bulk updates (bulk updates bypass Scout indexing)
        $expenseIds = $check->expenses()->pluck('id')->toArray();
        $transactionIds = $check->transactions()->pluck('id')->toArray();

        // Soft-delete expenses tied to this check so they stop counting toward totals
        $check->expenses()->delete();
        $check->timesheets()->update(['check_id' => null]);
        $check->transactions()->update(['check_id' => null]);

        // Re-index expenses in Meilisearch after soft-delete
        if (! empty($expenseIds)) {
            \App\Models\Expense::withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', $expenseIds)
                ->searchable();
        }

        // Re-index transactions in Meilisearch after bulk update
        if (! empty($transactionIds)) {
            \App\Models\Transaction::withoutGlobalScopes()
                ->whereIn('id', $transactionIds)
                ->searchable();
        }
    }

    /**
     * Handle the Check "restored" event.
     */
    public function restored(Check $check): void
    {
        // Restore expenses that were soft-deleted when the check was deleted
        $restoredCount = $check->expenses()->onlyTrashed()->restore();

        // Re-index restored expenses in Meilisearch
        if ($restoredCount > 0) {
            $check->expenses()->searchable();
        }
    }

    /**
     * Handle the Check "force deleted" event.
     */
    public function forceDeleted(Check $check): void
    {
        //
    }
}
