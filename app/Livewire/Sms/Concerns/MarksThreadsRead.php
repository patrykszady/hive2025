<?php

namespace App\Livewire\Sms\Concerns;

use App\Services\Sms\ThreadReadMarker;

/**
 * "Mark as read" on a row of a messages list without opening it — a swipe on
 * a phone, a button on hover with a pointer (x-sms.mark-read-swipe). Shared
 * by the lists that draw conversation rows so they cannot drift apart.
 */
trait MarksThreadsRead
{
    /**
     * Marks the conversation read for the signed-in person and takes its
     * texts out of their notifications. A thread that is not theirs to open
     * is left alone (ThreadReadMarker checks, the id comes from the browser).
     */
    public function markRead(int $threadId, ThreadReadMarker $marker): void
    {
        $user = auth()->user();

        if (! $user || $marker->markRead($user, $threadId) === null) {
            return;
        }

        $this->dispatch('sms-thread-read');
        $this->dispatch('notification-read');
    }
}
