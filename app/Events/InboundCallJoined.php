<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a recipient successfully joins an inbound call conference.
 * Used by the SMS UI to show the "On Call ... Add to Call" bar so the user
 * can invite additional participants while on the call.
 */
class InboundCallJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $callLogId,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->userId}"),
        ];
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'call_log_id' => $this->callLogId,
        ];
    }
}
