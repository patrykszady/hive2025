<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;

/**
 * Homeowner-submitted scheduling times, read exactly like
 * App\Livewire\Tasks\TaskCreate's own
 * servicePreferredSlots()/showAwaitingClientAvailabilityCard()/
 * servicePreferredSubmittedAt()/homeownerAvailability() — pulled out so the
 * Hive Contractors app's task screen can show the same data (the "apply a
 * slot" actions themselves stay pure form mutations on each caller's own
 * dates/time_settings, same as the web; nothing here writes anything).
 */
class TaskPreferredTimes
{
    /**
     * Maps a homeowner-selected time frame to concrete arrival start/end
     * times — identical to TaskCreate::SERVICE_TIME_FRAMES.
     *
     * @var array<string, array{start: string, end: string}>
     */
    public const SERVICE_TIME_FRAMES = [
        '7-9 AM' => ['start' => '07:00', 'end' => '09:00'],
        '9-11 AM' => ['start' => '09:00', 'end' => '11:00'],
        '11-1 PM' => ['start' => '11:00', 'end' => '13:00'],
        '1-3 PM' => ['start' => '13:00', 'end' => '15:00'],
        '3-5 PM' => ['start' => '15:00', 'end' => '17:00'],
    ];

    /**
     * The homeowner's submitted preferred service times for the project,
     * grouped by day — mirrors TaskCreate::servicePreferredSlots() exactly,
     * including the "only an unscheduled task, covered by the saved
     * task_ids" gating. $isApplied, when given, marks each time entry the
     * same way TaskCreate::servicePreferredSlotIsApplied() does (against
     * that caller's own in-progress dates/time_settings) — omitted
     * entirely for a caller with no live form to check against (every
     * entry is then just `{time}`, no `applied` key), such as the app's
     * read-only lookup.
     *
     * @param  ?callable(string $date, string $time): bool  $isApplied
     * @return array<int, array{date: string, label: string, times: array<int, array{time: string, applied?: bool}>}>
     */
    public function groupedSlots(?Project $project, ?Task $task, ?callable $isApplied = null): array
    {
        if (! $project) {
            return [];
        }

        // Homeowner preferred times only apply to unscheduled/pending tasks.
        if ($task?->start_date) {
            return [];
        }

        if (! $this->isCoveredForTask($project, $task?->id)) {
            return [];
        }

        $slots = (array) ($project->service_availability['slots'] ?? []);

        if (empty($slots)) {
            return [];
        }

        return collect($slots)
            ->filter(fn ($slot) => is_array($slot) && isset($slot['date'], $slot['time']))
            ->groupBy('date')
            ->sortKeys()
            ->map(fn ($group, string $date) => [
                'date' => $date,
                'label' => Carbon::parse($date)->format('D, M j'),
                'times' => collect(self::inDayOrder($group->pluck('time')->unique()))
                    ->map(fn (string $time) => $isApplied
                        ? ['time' => $time, 'applied' => $isApplied($date, $time)]
                        : ['time' => $time])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Time frames in the order of the day — Anytime first, then by start
     * time (7-9 AM before 11-1 PM before 3-5 PM) — rather than the order the
     * homeowner ticked them. Anything unrecognised keeps its place at the end.
     *
     * @param  iterable<string>  $times
     * @return list<string>
     */
    public static function inDayOrder(iterable $times): array
    {
        return collect($times)
            ->sortBy(fn (string $time) => $time === 'Anytime' ? '00:00' : (self::SERVICE_TIME_FRAMES[$time]['start'] ?? '99:99'))
            ->values()
            ->all();
    }

    /**
     * Show the pending-availability cue for a service-call task when there
     * are no currently-applicable homeowner-submitted slots to apply —
     * mirrors TaskCreate::showAwaitingClientAvailabilityCard().
     *
     * @param  array<int, mixed>  $groupedSlots  this project/task's own groupedSlots() result
     */
    public function showAwaitingCard(?Project $project, ?Task $task, array $groupedSlots): bool
    {
        if (! $project) {
            return false;
        }

        if ($task?->start_date) {
            return false;
        }

        if ($groupedSlots !== []) {
            return false;
        }

        $project->loadMissing('latestStatus');

        return (int) ($project->latestStatus->status_code ?? 0) === 8;
    }

    /** When the homeowner submitted their availability (ISO string), if any. */
    public function submittedAt(?Project $project): ?string
    {
        return $project?->service_availability['submitted_at'] ?? null;
    }

    /**
     * Whether the homeowner's submitted preferred times cover the task
     * currently being edited — mirrors
     * TaskCreate::currentTaskCoveredByPreferredTimes() exactly.
     */
    public function isCoveredForTask(Project $project, ?int $taskId): bool
    {
        if ((array) data_get($project->service_availability, 'slots', []) === []) {
            return false;
        }

        $savedTaskIds = collect((array) data_get($project->service_availability, 'task_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->all();

        if ($savedTaskIds === []) {
            return true;
        }

        return $taskId !== null && $taskId > 0 && in_array($taskId, $savedTaskIds, true);
    }

    /**
     * The times the homeowner picked on the public scheduling page, read
     * LIVE off their lead — mirrors TaskCreate::homeownerAvailability()
     * exactly (only future, still-bookable slots are offered).
     *
     * @return array{times: array<int, array{date: string, time: string}>, updated: ?string, preference: ?string}|null
     */
    public function homeownerAvailability(?Project $project): ?array
    {
        if (! $project) {
            return null;
        }

        $client = $project->client_id ? Client::withoutGlobalScopes()->find($project->client_id) : null;

        if (! $client) {
            return null;
        }

        $lead = Lead::latestForClient($client);

        $times = collect((array) ($lead?->lead_data['availability'] ?? []))
            ->filter(fn ($slot) => is_array($slot) && Lead::slotIsBookable($slot))
            ->values()
            ->all();

        if ($times === []) {
            return null;
        }

        return [
            'times' => $times,
            'updated' => $lead->lead_data['availability_updated_at'] ?? null,
            'preference' => $lead->lead_data['meeting_preference'] ?? null,
        ];
    }
}
