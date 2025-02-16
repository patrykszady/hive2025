<?php

namespace App\Observers;

use App\Models\Vendor;

class VendorObserver
{
    /**
     * Handle the Vendor "created" event.
     */
    public function created(Vendor $vendor): void
    {
        //
    }

    /**
     * Handle the Vendor "updated" event.
     */
    public function updated(Vendor $vendor): void
    {
        //5-23-2023 should be a Policy... "if auth()->user()->vendor CAN update $this->vendor
        //If $this->vendor = auth()->user()->vendor
        if ($vendor->id == auth()->user()->vendor->id) {
            //Update Client if $vendor->client
            if ($vendor->client()->exists()) {
                $client = $vendor->client;

                $client->business_name = $vendor->business_name;
                $client->address = $vendor->address;
                $client->address_2 = $vendor->address_2;
                $client->city = $vendor->city;
                $client->state = $vendor->state;
                $client->zip_code = $vendor->zip_code;
                $client->home_phone = $vendor->business_phone;

                $client->update();
                // $client = Client::findOrFail($this->vendor->client->id)->fill([
                //     'business_name' => $this->vendor->business_name,
                //     'address' => $this->vendor->address,
                //     'address_2' => $this->vendor->address_2,
                //     'city' => $this->vendor->city,
                //     'state' => $this->vendor->state,
                //     'zip_code' => $this->vendor->zip_code,
                //     'home_phone' => $this->vendor->business_phone,
                // ]);

                //DOING THIS on UserVendorObserver now
                //sync client->users with vendor->users .. doesnt have to happen here
                // $client->users()->sync($vendor->users()->employed()->pluck('users.id')->toArray());
            }
        }
    }

    /**
     * Handle the Vendor "deleted" event.
     */
    public function deleted(Vendor $vendor): void
    {
        //
    }

    /**
     * Handle the Vendor "restored" event.
     */
    public function restored(Vendor $vendor): void
    {
        //
    }

    /**
     * Handle the Vendor "force deleted" event.
     */
    public function forceDeleted(Vendor $vendor): void
    {
        //
    }
}
