<?php

namespace App\Observers;

use App\Models\LineItem;

class LineItemObserver
{
    /**
     * Handle the LineItem "created" event.
     */
    public function created(LineItem $lineItem): void
    {
        //
    }

    public function creating(LineItem $lineItem)
    {
        $lineItem->belongs_to_vendor_id = auth()->user()->vendor->id;
    }

    /**
     * Handle the LineItem "updated" event.
     */
    public function updated(LineItem $lineItem): void
    {
        //
    }

    /**
     * Handle the LineItem "deleted" event.
     */
    public function deleted(LineItem $lineItem): void
    {
        //
    }

    /**
     * Handle the LineItem "restored" event.
     */
    public function restored(LineItem $lineItem): void
    {
        //
    }

    /**
     * Handle the LineItem "force deleted" event.
     */
    public function forceDeleted(LineItem $lineItem): void
    {
        //
    }
}
