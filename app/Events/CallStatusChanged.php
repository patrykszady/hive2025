<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A call's lifecycle moved (initiated / answered / bridged / hung up) — lets
 * the "In a call" badge appear and, more importantly, VANISH the second the
 * hangup webhook lands, instead of lingering until a page reload.
 */
class CallStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?int $callLogId,
        public string $eventType,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sms.notifications'),
        ];
    }
}
