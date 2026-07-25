<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sent message's carrier status changed (delivered / failed) — lets the open
 * conversation update the bubble's status badge in real time, same channel the
 * incoming-message event uses.
 */
class SmsMessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $messageId,
        public int $threadId,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sms.notifications'),
        ];
    }
}
