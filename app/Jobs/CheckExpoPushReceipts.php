<?php

namespace App\Jobs;

use App\Services\Notifications\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The asynchronous half of Expo token pruning: SendExpoPushForNotification
 * dispatches this one, delayed ~15 minutes, with the tickets its own /send
 * call returned (Expo's push is "at-least-once handoff," not guaranteed
 * final delivery — a ticket can still fail after /send accepted it). Any
 * ticket whose receipt reports DeviceNotRegistered gets its PushToken
 * deleted, same pruning rule the immediate /send response already applies
 * in ExpoPushClient::send().
 */
class CheckExpoPushReceipts implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  list<array{ticket_id: string, token: string}>  $tickets
     */
    public function __construct(public array $tickets)
    {
        $this->onQueue('background');
    }

    /**
     * Execute the job.
     */
    public function handle(ExpoPushClient $expo): void
    {
        $expo->checkReceipts($this->tickets);
    }
}
