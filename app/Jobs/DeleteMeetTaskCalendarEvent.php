<?php

namespace App\Jobs;

use App\Services\MeetTaskCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteMeetTaskCalendarEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{event_id: string, grant_id: string, calendar_id: string|null, organizer_email: string|null}  $eventMeta
     */
    public function __construct(
        public int $taskId,
        public array $eventMeta,
    ) {
        $this->onQueue('default');
    }

    public function handle(MeetTaskCalendarService $meetTaskCalendarService): void
    {
        if (empty($this->eventMeta['event_id']) || empty($this->eventMeta['grant_id'])) {
            Log::channel('nylas')->info('Skipping Meet calendar event deletion: missing event metadata', [
                'task_id' => $this->taskId,
                'event_id' => $this->eventMeta['event_id'] ?? null,
                'grant_id' => $this->eventMeta['grant_id'] ?? null,
            ]);

            return;
        }

        $meetTaskCalendarService->deleteMeetEventByMeta($this->taskId, $this->eventMeta);
    }
}
