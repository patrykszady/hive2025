<?php

namespace App\Services\ActionItems;

use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\Sms\MessageTaskSuggestionBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Puts a visit a message sets — a measurement, a delivery, an inspection,
 * an appointment on a date — onto the project's own task for it.
 *
 * Kama at Ultimate Stone emailed "the measurement is scheduled for
 * Thursday, 10/29th between 11-1pm" on 949 Elm, which already had an
 * undated "Counter Measure"; Patryk (2026-10-26): it "should have added
 * times and date to the Counter Measure task already on the project". The
 * planner's reading pass (ProjectActionItemExtractor) marks such an item a
 * visit; this dates the task it matches (MessageTaskSuggestionBuilder's
 * matcher, the one texts and emails use) and turns the item into that task.
 *
 * Only where a message may decide the day:
 *  - the task has no date yet;
 *  - it is already on that day (a missing time is filled in, a time the
 *    team set is kept);
 *  - its date came from an earlier message (options.scheduled_from), and
 *    this message is newer — "Actually, it's Thursday" moves it.
 * A day the team set by hand is never moved by a message; the item stays
 * on the planner for a person to decide. Days already past are left alone.
 *
 * An inspection with no task to date gets one of its own (Patryk,
 * 2026-10-27): the Village of Northbrook's "Water Service — Confirmation of
 * Scheduled Inspection" for 3154 Violet Ln (10/29, 1–4 PM) should have
 * become a "Water Service Inspection" task. The building department sets
 * that day, so it is not a suggestion to weigh. Other visits with nothing to
 * date stay on the planner as before.
 *
 * Written without model events: a message-set date texts nobody (no vendor
 * availability request, no service-call text to the homeowner); the change
 * is kept in the task's activity log instead.
 */
class ScheduledVisitApplier
{
    public function __construct(private MessageTaskSuggestionBuilder $matcher) {}

    /**
     * @param  array{at: Carbon, source: array<string, mixed>}  $candidate  the mention that set it
     */
    public function apply(ProjectActionItem $item, array $candidate): ?Task
    {
        $date = $item->due_on?->toDateString();

        if (! $date || $date < Carbon::now(self::timezone($item))->toDateString()) {
            return null;
        }

        $task = $this->linkedTask($item) ?? $this->matcher->matchExistingTask((int) $item->project_id, (string) $item->title, $date);

        if (! $task && self::isInspection((string) $item->title)) {
            return $this->createInspection($item, $candidate, $date);
        }

        if (! $task || (int) $task->project_id !== (int) $item->project_id) {
            return null;
        }

        $options = json_decode(json_encode($task->options ?? []), true) ?: [];
        $dates = array_values(array_filter((array) ($options['dates'] ?? [])));
        $from = is_array($options['scheduled_from'] ?? null) ? $options['scheduled_from'] : null;
        $at = Carbon::instance($candidate['at']);
        $setByMessage = $from !== null && ! empty($from['at']);
        $times = (array) ($options['time_settings'] ?? []);

        if ($dates !== []) {
            if ($setByMessage && Carbon::parse($from['at'])->gt($at)) {
                return null;
            }

            if (! $setByMessage && $dates !== [$date]) {
                return null;
            }
        }

        $teamTime = ! $setByMessage && $dates === [$date] && ! empty($times[$date]['use_time']);
        $time = $item->start_time && ! $teamTime
            ? ['use_time' => true, 'start_time' => $item->start_time, 'end_time' => $item->end_time ?: $item->start_time]
            : ($times[$date] ?? ['use_time' => false, 'start_time' => null, 'end_time' => null]);

        $before = ['start_date' => $task->start_date?->toDateString(), 'end_date' => $task->end_date?->toDateString(), 'dates' => $dates, 'time_settings' => $times];

        $source = $candidate['source'];
        $options['dates'] = [$date];
        $options['time_settings'] = [$date => $time];
        $options['scheduled_from'] = [
            'kind' => $source['kind'] ?? null,
            'id' => $source['id'] ?? null,
            'at' => $at->toIso8601String(),
            'excerpt' => $source['excerpt'] ?? null,
            'action_item_id' => $item->id,
        ];

        Task::withoutEvents(fn () => $task->forceFill(['start_date' => $date, 'end_date' => $date, 'options' => $options])->save());

        activity('tasks')
            ->performedOn($task)
            ->event('updated')
            ->withProperties([
                'old' => $before,
                'attributes' => ['start_date' => $date, 'end_date' => $date, 'dates' => [$date], 'time_settings' => [$date => $time]],
                'scheduled_from' => $options['scheduled_from'],
            ])
            ->log('Scheduled from '.($source['kind'] === 'email' ? 'an email' : ($source['kind'] === 'sms' ? 'a text' : 'a call')));

        $item->update(['status' => ProjectActionItem::STATUS_TASK, 'task_id' => $task->id]);

        return $task;
    }

    public static function isInspection(string $title): bool
    {
        return (bool) preg_match('/\binspect(ion|ions|ed)?\b/i', $title);
    }

    /**
     * A new task for an inspection the building department set, dated and
     * timed from the message. Written without model events like a dated one
     * (no texts go out); owned by the project's company and by whoever is
     * its first admin, since no one is signed in to the reading pass.
     *
     * @param  array{at: Carbon, source: array<string, mixed>}  $candidate
     */
    protected function createInspection(ProjectActionItem $item, array $candidate, string $date): ?Task
    {
        $project = $item->project()->withoutGlobalScopes()->first();
        $creatorId = $project ? $this->companyAdminId((int) $project->belongs_to_vendor_id) : null;

        if (! $project || ! $creatorId) {
            return null;
        }

        $source = $candidate['source'];
        $time = $item->start_time
            ? ['use_time' => true, 'start_time' => $item->start_time, 'end_time' => $item->end_time ?: $item->start_time]
            : ['use_time' => false, 'start_time' => null, 'end_time' => null];
        $title = Str::of((string) $item->title)->squish()->limit(120, '')->value();

        $task = Task::withoutEvents(fn () => Task::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
            'created_by_user_id' => $creatorId,
            'title' => $title,
            'type' => 'Task',
            'order' => 0,
            'start_date' => $date,
            'end_date' => $date,
            'user_ids' => [],
            'options' => [
                'dates' => [$date],
                'time_settings' => [$date => $time],
                'checklist' => [],
                'scheduled_from' => [
                    'kind' => $source['kind'] ?? null,
                    'id' => $source['id'] ?? null,
                    'at' => Carbon::instance($candidate['at'])->toIso8601String(),
                    'excerpt' => $source['excerpt'] ?? null,
                    'action_item_id' => $item->id,
                ],
            ],
        ]));

        activity('tasks')
            ->performedOn($task)
            ->event('created')
            ->withProperties(['attributes' => ['title' => $title, 'start_date' => $date, 'time_settings' => [$date => $time]], 'scheduled_from' => $task->options->scheduled_from ?? null])
            ->log('Created from '.(($source['kind'] ?? null) === 'email' ? 'an email' : (($source['kind'] ?? null) === 'sms' ? 'a text' : 'a call')));

        $item->update(['status' => ProjectActionItem::STATUS_TASK, 'task_id' => $task->id]);

        return $task;
    }

    /** The company's first admin — who a task the reading pass makes is credited to. */
    protected function companyAdminId(int $vendorId): ?int
    {
        $id = \Illuminate\Support\Facades\DB::table('user_vendor')
            ->where('vendor_id', $vendorId)
            ->where('role_id', 1)
            ->orderBy('user_id')
            ->value('user_id');

        return $id ? (int) $id : null;
    }

    /** The task this item already became, if it still exists on the project. */
    protected function linkedTask(ProjectActionItem $item): ?Task
    {
        return $item->task_id
            ? Task::withoutGlobalScopes()->whereNull('deleted_at')->where('project_id', $item->project_id)->find($item->task_id)
            : null;
    }

    /** The company's time zone: "today" is Chicago's, not the server's. */
    public static function timezone(ProjectActionItem $item): string
    {
        $vendorId = $item->project?->belongs_to_vendor_id;
        $zone = $vendorId ? Vendor::withoutGlobalScopes()->whereKey($vendorId)->value('timezone') : null;

        return is_string($zone) && $zone !== '' ? $zone : 'America/Chicago';
    }
}
