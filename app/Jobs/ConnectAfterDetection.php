<?php

namespace App\Jobs;

use App\Http\Controllers\Api\TelnyxWebhookController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A leg heard its whole prompt (a team member's "Call from…", an invited
 * person's "…has invited you to a call") but answering machine detection
 * has not said person or machine yet: it is connected on the verdict, and
 * this connects it anyway if none comes (Greg's leg on call 2664 never got
 * one). Does nothing once the verdict has connected or dropped the leg.
 */
class ConnectAfterDetection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $callControlId) {}

    public function handle(TelnyxWebhookController $webhook): void
    {
        $webhook->connectAfterScreening($this->callControlId, 'no detection verdict in time');
        $webhook->joinInviteAfterDetection($this->callControlId, 'no detection verdict in time');
    }
}
