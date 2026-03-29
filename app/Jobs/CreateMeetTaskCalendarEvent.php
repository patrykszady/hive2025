<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\MeetTaskCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateMeetTaskCalendarEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $taskId, public ?int $actorUserId = null)
    {
        $this->onQueue('default');
    }

    public function handle(MeetTaskCalendarService $meetTaskCalendarService): void
    {
        $task = Task::query()->find($this->taskId);

        if (! $task) {
            Log::channel('nylas')->warning('CreateMeetTaskCalendarEvent: task not found', [
                'task_id' => $this->taskId,
            ]);

            return;
        }

        $meetTaskCalendarService->createMeetEvent($task, $this->actorUserId);
    }
}
