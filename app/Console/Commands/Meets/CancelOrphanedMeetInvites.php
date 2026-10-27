<?php

namespace App\Console\Commands\Meets;

use App\Models\Task;
use App\Services\MeetTaskCalendarService;
use Illuminate\Console\Command;

/**
 * Cancel calendar invites whose Meet is no longer booked: the task lost its
 * date, is no longer a Meet, or was deleted while the invite stayed on the
 * calendar. Before 2026-10-27 unscheduling a Meet never touched its event
 * (task 1216, Carla's 10 am consult); the observer handles it now, and this
 * cleans up what was left behind. Attendees get the calendar's cancellation.
 *
 *   php artisan meets:cancel-orphaned-invites --dry-run
 *   php artisan meets:cancel-orphaned-invites
 */
class CancelOrphanedMeetInvites extends Command
{
    protected $signature = 'meets:cancel-orphaned-invites
        {--dry-run : List the invites that would be cancelled without touching the calendar}
        {--all : Also cancel invites for meetings whose day has already passed}';

    protected $description = 'Cancel calendar invites of Meets that lost their date, their type or were deleted';

    public function handle(MeetTaskCalendarService $calendar): int
    {
        $orphans = Task::withTrashed()
            ->whereNotNull('options')
            ->where('options', 'like', '%nylas_meet_event%')
            ->where(function ($query): void {
                $query->whereNull('start_date')
                    ->orWhere('type', '!=', 'Meet')
                    ->orWhereNotNull('deleted_at');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Task $task) => filled(data_get($task->options, 'nylas_meet_event.event_id')) && filled(data_get($task->options, 'nylas_meet_event.grant_id')));

        if ($orphans->isEmpty()) {
            $this->info('No orphaned Meet invites.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($orphans as $task) {
            $meta = (array) data_get($task->options, 'nylas_meet_event');
            $why = $task->deleted_at ? 'deleted' : ($task->type !== 'Meet' ? 'no longer a Meet' : 'no date');
            $day = $this->plannedDay($task);

            // A meeting whose day has passed gets no cancellation now — the
            // guests would read it as news about a meeting long over.
            if (! $this->option('all') && $day && $day->lt(today())) {
                $this->line(sprintf('Leaving task %d "%s" (%s, was %s) — past; --all cancels it too', $task->id, $task->title, $why, $day->toDateString()));

                continue;
            }

            $this->line(sprintf('%s task %d "%s" (%s%s) — event %s', $this->option('dry-run') ? 'Would cancel' : 'Cancelling', $task->id, $task->title, $why, $day ? ', '.$day->toDateString() : '', mb_substr((string) $meta['event_id'], -12)));
            $cancelled++;

            if ($this->option('dry-run')) {
                continue;
            }

            $calendar->deleteMeetEventByMeta($task->id, $meta);

            $options = (array) json_decode(json_encode($task->options), true);
            unset($options['nylas_meet_event']);
            $task->updateQuietly(['options' => $options]);
        }

        $this->info(sprintf('%s %d invite(s).', $this->option('dry-run') ? 'Found' : 'Cancelled', $cancelled));

        return self::SUCCESS;
    }

    /** The day the meeting was booked for: the task's date, else the day its times were set for. */
    protected function plannedDay(Task $task): ?\Illuminate\Support\Carbon
    {
        if ($task->start_date) {
            return $task->start_date->copy()->startOfDay();
        }

        $days = array_merge(
            array_keys((array) json_decode(json_encode(data_get($task->options, 'time_settings', [])), true)),
            (array) json_decode(json_encode(data_get($task->options, 'dates', [])), true),
        );
        $days = array_filter($days, fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d));

        return $days ? \Illuminate\Support\Carbon::parse(max($days))->startOfDay() : null;
    }
}
