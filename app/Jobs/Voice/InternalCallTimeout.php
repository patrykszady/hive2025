<?php

namespace App\Jobs\Voice;

use App\Models\CallLog;
use App\Services\Voice\InternalCalls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs InternalCalls::RING_SECONDS after an internal call starts: a call
 * nobody took is ended with a word to the caller and a missed-call note.
 */
class InternalCallTimeout implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $callLogId)
    {
        $this->onQueue('default');
    }

    public function handle(InternalCalls $calls): void
    {
        $callLog = CallLog::find($this->callLogId);
        if (! $callLog || ($callLog->metadata['type'] ?? null) !== InternalCalls::TYPE) {
            return;
        }

        $calls->timeOut($callLog);
    }
}
