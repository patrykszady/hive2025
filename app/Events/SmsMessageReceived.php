<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SmsMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $threadId)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("sms.thread.{$this->threadId}"),
            new PrivateChannel('sms.notifications'),
        ];
    }
}
