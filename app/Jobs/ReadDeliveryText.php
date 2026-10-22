<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\Deliveries\DeliveryTextIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads one inbound text for a supplier's delivery (DeliveryTextIntake);
 * queued by the Telnyx webhook for every inbound text.
 */
class ReadDeliveryText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $messageId)
    {
    }

    public function handle(DeliveryTextIntake $intake): void
    {
        $message = SmsMessage::withoutGlobalScopes()->find($this->messageId);

        if ($message) {
            $intake->consider($message);
        }
    }
}
