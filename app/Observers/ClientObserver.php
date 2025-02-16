<?php

namespace App\Observers;

use App\Models\Client;

class ClientObserver
{
    /**
     * Handle the Client "created" event.
     */
    public function created(Client $client): void
    {
        //when creating from VendorRegistration with vendor_id
        //attach all $adding_vendor->users to $client
        if (! is_null($client->vendor)) {
            $client->users()->attach($client->vendor->users()->employed()->pluck('users.id')->toArray());
        }
    }

    public function creating(Client $client) {}

    /**
     * Handle the Client "updated" event.
     */
    public function updated(Client $client): void
    {
        //
    }

    /**
     * Handle the Client "deleted" event.
     */
    public function deleted(Client $client): void
    {
        //
    }

    /**
     * Handle the Client "restored" event.
     */
    public function restored(Client $client): void
    {
        //
    }

    /**
     * Handle the Client "force deleted" event.
     */
    public function forceDeleted(Client $client): void
    {
        //
    }
}
