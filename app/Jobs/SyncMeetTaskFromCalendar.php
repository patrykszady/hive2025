<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\MeetTaskCalendarService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A Meet's calendar event changed (Nylas event.updated): bring the task to
 * the event's day and time. Unique until it starts, so the several
 * notifications one Outlook move sends read the event once.
 */
class SyncMeetTaskFromCalendar implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public int $taskId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->taskId;
    }

    public function handle(MeetTaskCalendarService $meetTaskCalendarService): void
    {
        $task = Task::query()->find($this->taskId);

        if (! $task) {
            return;
        }

        $meetTaskCalendarService->syncFromCalendar($task);
    }
}
