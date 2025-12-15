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
        // Get transaction IDs before bulk update (bulk update bypasses Scout indexing)
        $transactionIds = $check->transactions()->pluck('id')->toArray();

        // $check->expenses()->delete();
        $check->expenses()->update(['check_id' => null]);
        $check->timesheets()->update(['check_id' => null]);
        $check->transactions()->update(['check_id' => null]);

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
        //
    }

    /**
     * Handle the Check "force deleted" event.
     */
    public function forceDeleted(Check $check): void
    {
        //
    }
}
