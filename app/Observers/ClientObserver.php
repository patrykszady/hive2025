<?php

namespace App\Observers;

use App\Models\Client;
use App\Services\SmsThreadLinker;

class ClientObserver
{
    public function __construct(protected SmsThreadLinker $smsThreadLinker)
    {
    }

    /**
     * Handle the Client "created" event.
     */
    public function created(Client $client): void
    {
        $this->smsThreadLinker->linkThreadsForClient($client);
    }

    public function creating(Client $client) {}

    /**
     * Handle the Client "updated" event.
     */
    public function updated(Client $client): void
    {
        if ($client->wasChanged('home_phone')) {
            $this->smsThreadLinker->linkThreadsForClient($client);
        }
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
