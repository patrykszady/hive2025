<?php

namespace App\Services\Dashboard;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Hive Contractors app's Home tab: the same task schedule as the web
 * Hub's "Your Tasks" card (App\Livewire\Dashboard\VendorTasks), reshaped for
 * a phone screen instead of a grid — same source data as
 * App\Services\Planner\AppPlannerAgenda's day/task payloads, built
 * independently here since the Hub's window, grouping and "pending"/"later"
 * trays differ from the Planner's.
 *
 * Every app user's primary_vendor_id is 1 (EnsureAppUserAllowed), and
 * User::vendor() always resolves for that id, so this only ever mirrors
 * VendorTasks — App\Livewire\Dashboard\UserTasks (the "no vendor" branch of
 * the web Hub) is unreachable for an app account and has no counterpart
 * here.
 */
class AppHomeAgenda
{
    public const TIMEZONE = 'America/Chicago';

    public function __construct(private VendorTaskAgenda $tasks)
    {
    }

    /** "Today" for the Home tab, independent of any browser-timezone cookie — the app carries no such cookie. */
    public function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * @return array{today: string, task_count: int, distinct_project_count: int, days: list<array{date: string, is_today: bool, is_yesterday: bool, is_tomorrow: bool, is_weekend: bool, groups: list<array>}>, later: list<array{date: string, groups: list<array>}>, unscheduled: list<array{project: ?array, tasks: list<array>}>}
     */
    public function build(User $user, Vendor $vendor): array
    {
        $today = $this->today();
        $userId = (int) $user->id;
        $vendorId = (int) $vendor->id;

        $grouped = $this->tasks->groupedTasks($vendor, $userId, $today);
        $later = $this->tasks->laterTasks($vendor, $userId, $today);
        $unscheduled = $this->tasks->unscheduledTasks($vendor, $userId);

        return [
            'today' => $today->format('Y-m-d'),
            'task_count' => $this->tasks->taskCount($vendor, $userId, $today),
            'distinct_project_count' => $this->tasks->distinctProjectCount($grouped),
            'days' => $this->daysPayload($grouped, $today, $vendorId),
            'later' => $this->dayGroupsPayload($later, $vendorId),
            'unscheduled' => $this->unscheduledPayload($unscheduled, $vendorId),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Task>>  $grouped
     * @return list<array{date: string, is_today: bool, is_yesterday: bool, is_tomorrow: bool, is_weekend: bool, groups: list<array>}>
     */
    private function daysPayload(Collection $grouped, Carbon $today, int $vendorId): array
    {
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->copy()->subDay()->format('Y-m-d');
        $tomorrowStr = $today->copy()->addDay()->format('Y-m-d');

        return $grouped
            ->map(function (Collection $tasksForDay, string $date) use ($todayStr, $yesterdayStr, $tomorrowStr, $vendorId): array {
                return [
                    'date' => $date,
                    'is_today' => $date === $todayStr,
                    'is_yesterday' => $date === $yesterdayStr,
                    'is_tomorrow' => $date === $tomorrowStr,
                    'is_weekend' => Carbon::parse($date)->isWeekend(),
                    'groups' => $this->projectGroups($tasksForDay, $date, $vendorId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, Collection<int, Task>>  $grouped
     * @return list<array{date: string, groups: list<array>}>
     */
    private function dayGroupsPayload(Collection $grouped, int $vendorId): array
    {
        return $grouped
            ->map(fn (Collection $tasksForDay, string $date): array => [
                'date' => $date,
                'groups' => $this->projectGroups($tasksForDay, $date, $vendorId),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array{project: ?array, tasks: list<array>}>
     */
    private function projectGroups(Collection $tasks, string $date, int $vendorId): array
    {
        return $tasks
            ->groupBy(fn (Task $task): int => $task->project_id ?? 0)
            ->map(function (Collection $projectTasks) use ($date, $vendorId): array {
                $project = $projectTasks->first()->project;

                return [
                    'project' => $project ? $this->projectPayload($project, $vendorId) : null,
                    'tasks' => $projectTasks->map(fn (Task $task): array => $this->taskPayload($task, $date))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array{project: ?array, tasks: list<array>}>
     */
    private function unscheduledPayload(Collection $tasks, int $vendorId): array
    {
        return $tasks
            ->groupBy(fn (Task $task): int => $task->project_id ?? 0)
            ->map(function (Collection $projectTasks) use ($vendorId): array {
                $project = $projectTasks->first()->project;

                return [
                    'project' => $project ? $this->projectPayload($project, $vendorId) : null,
                    'tasks' => $projectTasks->map(fn (Task $task): array => $this->undatedTaskPayload($task))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{id: int, name: ?string, short_address: ?string, status: ?array{code: int, title: string, color: string}} */
    private function projectPayload(Project $project, int $vendorId): array
    {
        $status = $project->latestVendorStatus($vendorId);

        return [
            'id' => $project->id,
            'name' => $project->project_name,
            'short_address' => $project->short_address,
            'status' => $status ? [
                'code' => $status->status_code,
                'title' => $status->title,
                'color' => $status->badge_color,
            ] : null,
        ];
    }

    /**
     * @return array{id: int, title: string, type: ?string, arrival: ?string, previous_arrival: ?string, day_counter: ?array{current: int, total: int}, progress: ?int, notes: ?string, users: list<string>, is_meet: bool, trashed: bool, preferred_time_indicator: ?string, checklist: list<array{index: int, text: string, completed: bool}>, checklist_interactive: bool}
     */
    private function taskPayload(Task $task, string $date): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'arrival' => $task->getArrivalTimeLabel($date),
            'previous_arrival' => $task->getPreviousArrivalTimeLabel($date),
            'day_counter' => $this->dayCounter($task, $date),
            'progress' => $task->progress !== null ? (int) $task->progress : null,
            'notes' => $this->notesFor($task),
            'users' => $this->taskUserNames($task),
            'is_meet' => $task->type === 'Meet',
            'trashed' => $task->trashed(),
            'preferred_time_indicator' => $task->preferredTimeIndicator(),
            'checklist' => $this->checklistPayload($task),
            'checklist_interactive' => $this->checklistInteractive($task),
        ];
    }

    /** @return array{id: int, title: string, type: ?string, notes: ?string, users: list<string>, is_meet: bool, trashed: bool, preferred_time_indicator: ?string, checklist: list<array{index: int, text: string, completed: bool}>, checklist_interactive: bool} */
    private function undatedTaskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'notes' => $this->notesFor($task),
            'users' => $this->taskUserNames($task),
            'is_meet' => $task->type === 'Meet',
            'trashed' => $task->trashed(),
            'preferred_time_indicator' => $task->preferredTimeIndicator(),
            'checklist' => $this->checklistPayload($task),
            // Undated tasks have no start_date, so checklistInteractive() is
            // always false for them — same as x-schedule.task-details'
            // $interactive, which requires a start_date too.
            'checklist_interactive' => false,
        ];
    }

    /**
     * Mirrors resources/views/components/schedule/task-details.blade.php's
     * $detailChecklist: blank rows are skipped, original indexes preserved
     * so a toggle writes back by real position.
     *
     * @return list<array{index: int, text: string, completed: bool}>
     */
    public function checklistPayload(Task $task): array
    {
        return collect((array) data_get($task->options, 'checklist', []))
            ->map(fn ($item) => is_scalar($item) ? ['text' => $item] : (array) $item)
            ->map(fn (array $item, int $index): array => [
                'index' => $index,
                'text' => trim((string) ($item['text'] ?? '')),
                'completed' => (bool) ($item['completed'] ?? false),
            ])
            ->filter(fn (array $item): bool => $item['text'] !== '')
            ->values()
            ->all();
    }

    /** Mirrors task-details.blade.php's $interactive: boxes go live on the task's scheduled day, not before. */
    private function checklistInteractive(Task $task): bool
    {
        return (bool) $task->start_date
            && $task->start_date->copy()->startOfDay()->lte($this->today());
    }

    /**
     * Mirrors x-schedule.task-details.blade.php: system-written provenance
     * notes are bookkeeping, not something to show on the card.
     */
    private function notesFor(Task $task): ?string
    {
        $notes = trim((string) ($task->notes ?? ''));

        if ($notes === '' || Str::startsWith($notes, 'Booked from lead email reply')) {
            return null;
        }

        return $notes;
    }

    /** Mirrors PlannerTaskCard::dayCounter() (restated in AppPlannerAgenda too): position within the FULL picked-dates list, not the requested window. */
    private function dayCounter(Task $task, string $date): ?array
    {
        $selectedDates = collect($task->options->dates ?? [])
            ->filter()
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();

        $total = count($selectedDates);

        if ($total <= 1) {
            return null;
        }

        sort($selectedDates);
        $current = array_search($date, $selectedDates, true);

        if ($current === false) {
            return null;
        }

        return ['current' => $current + 1, 'total' => $total];
    }

    /** @return list<string> */
    private function taskUserNames(Task $task): array
    {
        return $task->users
            ->map(fn (User $user): string => trim((string) ($user->nickname ?: $user->first_name)))
            ->filter()
            ->values()
            ->all();
    }
}
