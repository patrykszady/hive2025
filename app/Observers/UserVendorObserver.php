<?php

namespace App\Observers;

use App\Models\UserVendor;
use App\Models\Vendor;

class UserVendorObserver
{
    /**
     * Handle the UserVendor "created" event.
     */
    public function created(UserVendor $userVendor): void
    {
        $user = $userVendor->pivotParent;

        //attach user_vendor to client if vendor_id = auth()->user()->vendor
        $user_vendor = Vendor::withoutGlobalScopes()->findOrFail($userVendor->vendor_id);

        //If $this->vendor = auth()->user()->vendor
        if ($user_vendor->id == auth()->user()->vendor->id) {
            //Update Client if $vendor->client
            if ($user_vendor->client()->exists()) {
                $user->clients()->attach($user_vendor->client);
            }
        }
    }

    public function creating(UserVendor $userVendor) {}

    /**
     * Handle the UserVendor "updated" event.
     */
    public function updated(UserVendor $userVendor): void {}

    /**
     * Handle the UserVendor "deleted" event.
     */
    public function deleted(UserVendor $userVendor): void
    {
        //
    }

    /**
     * Handle the UserVendor "restored" event.
     */
    public function restored(UserVendor $userVendor): void
    {
        //
    }

    /**
     * Handle the UserVendor "force deleted" event.
     */
    public function forceDeleted(UserVendor $userVendor): void
    {
        //
    }
}
