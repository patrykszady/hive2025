<?php

namespace App\Observers;

use App\Jobs\LookupEwccvForVendor;
use App\Models\VendorDoc;
use Carbon\Carbon;

class VendorDocObserver
{
    /**
     * Handle the VendorDoc "created" event.
     *
     * When a workers comp policy is added to the system, queue an EWCCV
     * lookup so we can verify the policy and (where possible) enable
     * EWCCV's email tracking notifications for cancellation/expiration.
     */
    public function created(VendorDoc $vendorDoc): void
    {
        $this->maybeDispatchEwccvLookup($vendorDoc);
    }

    /**
     * Handle the VendorDoc "updated" event.
     *
     * If the type is changed to "workers" or the policy number / expiration
     * date is updated, re-queue an EWCCV lookup.
     */
    public function updated(VendorDoc $vendorDoc): void
    {
        if (! $vendorDoc->wasChanged(['type', 'number', 'expiration_date'])) {
            return;
        }

        $this->maybeDispatchEwccvLookup($vendorDoc);
    }

    private function maybeDispatchEwccvLookup(VendorDoc $vendorDoc): void
    {
        if ($vendorDoc->type !== 'workers') {
            return;
        }

        if (! $vendorDoc->vendor_id) {
            return;
        }

        // Skip already-expired policies — EWCCV won't have useful data.
        if ($vendorDoc->expiration_date instanceof Carbon
            && $vendorDoc->expiration_date->isPast()
        ) {
            return;
        }

        LookupEwccvForVendor::dispatch($vendorDoc->id)->afterCommit();
    }
}
