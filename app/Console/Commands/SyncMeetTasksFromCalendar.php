<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\MeetTaskCalendarService;
use Illuminate\Console\Command;

/**
 * Move upcoming Meets to the day and time their calendar event now has — a
 * manual catch-up, not a schedule: day to day the event.updated webhook moves
 * a meet when its event changes. For meets moved while no notification
 * reached Hive (before it listened for calendar changes, or while the webhook
 * was failing). Re-runnable; a task already in step is left alone, and
 * nothing is ever written to the calendar.
 *
 * Moves made here are silent. The attendees heard from Outlook when the meet
 * moved, and a schedule-changed text about an old move would only confuse;
 * the webhook sends that text for moves as they happen.
 */
class SyncMeetTasksFromCalendar extends Command
{
    protected $signature = 'meet:sync-from-calendar
        {--task=* : Only these task ids, whatever their date}
        {--dry-run : Report the moves without saving}';

    protected $description = 'Move Meet tasks to the day and time their calendar event now has';

    public function handle(MeetTaskCalendarService $meetTaskCalendarService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $taskIds = array_values(array_filter(array_map('intval', (array) $this->option('task'))));
        $moved = 0;

        Task::query()
            ->where('type', 'Meet')
            ->whereNotNull('options->nylas_meet_event->event_id')
            ->when(
                $taskIds !== [],
                fn ($query) => $query->whereIn('id', $taskIds),
                fn ($query) => $query->whereDate('start_date', '>=', now()->subDay()->toDateString()),
            )
            ->with('project.createdByVendor')
            ->orderBy('id')
            ->each(function (Task $task) use ($meetTaskCalendarService, $dryRun, &$moved): void {
                $move = $meetTaskCalendarService->syncFromCalendar($task, $dryRun, notify: false);

                if ($move === null) {
                    return;
                }

                $moved++;
                $this->line(($dryRun ? '[dry-run] ' : '')."#{$task->id} {$task->title}: {$move['from']} → {$move['to']}");
            });

        $this->info("Done. meets moved={$moved}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
