<?php

namespace App\Services\Planner;

use App\Livewire\Planner\CardsIndex;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Hive Contractors app's Planner tab: the same week agenda, "To
 * schedule" tray, and action-items-from-calls-and-texts list as the web
 * planner (App\Livewire\Planner\CardsIndex), reshaped for a phone screen
 * instead of a grid.
 *
 * Project visibility is left entirely to Project's own global scope
 * (ProjectScope) — exactly like AppProjectDirectory — so nothing here needs
 * to pass a vendor id around for "can this user see this project".
 *
 * The web planner picks candidate projects with a MySQL-only
 * JSON_OVERLAPS() against tasks.options->dates. That won't run on the
 * SQLite the test suite (and this app's own tests) use, so project
 * selection here only looks at the planner status codes and each task's
 * start_date/end_date — which already bracket its picked dates (see
 * CardsIndex::scheduleTask()/moveTaskDay(), which always keep start_date
 * and end_date as the min/max of options.dates). Once a project is
 * selected, every date a task is actually booked on is decided in PHP
 * (taskBookedDates()), so a picked-dates task still lands on exactly the
 * right days.
 */
class AppPlannerAgenda
{
    public const TIMEZONE = 'America/Chicago';

    /**
     * Display order for the agenda's days: mirrors
     * CardsIndex::PLANNER_STATUS_PRIORITY (private there, so restated here)
     * — Active, Service Call, Prep, Scheduled.
     *
     * @var array<int, int>
     */
    private const STATUS_PRIORITY = [6 => 1, 8 => 2, 4 => 3, 5 => 4];

    /**
     * How far a fresh "upcoming"/"past"/"all" window reaches before the app
     * asks to load more — mirrors CardsIndex's initial
     * previousDaysLoaded/futureDaysLoaded (7 each). Picked slightly larger
     * here since the app has no desktop week-view fortnight to pad the
     * mobile agenda out to.
     */
    public const DEFAULT_WINDOW_DAYS = 14;

    /**
     * The window on either side of today never grows past this many days —
     * mirrors CardsIndex::MAX_DAYS_LOADED.
     */
    public const MAX_WINDOW_DAYS = 120;

    /** "Today" for the agenda, independent of any browser-timezone cookie — the app carries no such cookie. */
    public function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * This week, Monday to Sunday, in the agenda's timezone — kept for an
     * explicit `from`/`to` request with neither given (legacy callers).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function defaultRange(): array
    {
        $from = $this->today()->copy()->startOfWeek(Carbon::MONDAY);

        return [$from, $from->copy()->addDays(6)];
    }

    /**
     * Resolves the mobile agenda's day window from the same tri-state
     * filter the web's mobile agenda uses (App\Livewire\Planner\
     * CardsIndex::$filterDateRange — 'upcoming' | 'past' | 'all'): each
     * direction has its own natural default span and its own independently
     * growable size ($daysBack/$daysForward, null meaning "use the
     * default"), mirroring CardsIndex's previousDaysLoaded/futureDaysLoaded
     * paging in day-count form instead of a fixed calendar week.
     *
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int} [from, to, daysBack, daysForward]
     */
    public function resolveWindow(string $when, ?int $daysBack, ?int $daysForward): array
    {
        $today = $this->today();

        $back = $daysBack ?? match ($when) {
            'past', 'all' => self::DEFAULT_WINDOW_DAYS,
            default => 0,
        };
        $forward = $daysForward ?? match ($when) {
            'upcoming', 'all' => self::DEFAULT_WINDOW_DAYS,
            default => 0,
        };

        $back = max(0, min(self::MAX_WINDOW_DAYS, $back));
        $forward = max(0, min(self::MAX_WINDOW_DAYS, $forward));

        $from = $today->copy()->subDays($back);
        $to = match ($when) {
            'past' => $today->copy()->subDay(),
            default => $today->copy()->addDays($forward), // 'upcoming' and 'all'
        };

        return [$from, $to, $back, $forward];
    }

    /**
     * The week agenda for $user's vendor between $from and $to (inclusive),
     * plus the undated "To schedule" tray — narrowed by $filters exactly
     * like the web planner's filter toolbar/mobile Filters modal
     * (App\Livewire\Planner\CardsIndex): search (project address/client/
     * name OR task title/vendor), project_ids, status_codes, vendor_id,
     * user_ids and mine all combine with AND, same as there.
     *
     * $hidePending mirrors CardsIndex::mobileUnscheduledTasks() hiding the
     * "To schedule" tray entirely while viewing Past only — true when the
     * caller resolved $when === 'past'.
     *
     * @param  array{search?: string, project_ids?: list<int>, status_codes?: list<int>, vendor_id?: ?int, user_ids?: list<int>, mine?: bool}  $filters
     * @return array{from: string, to: string, today: string, days: list<array{date: string, is_today: bool, is_weekend: bool, groups: list<array{project: array, tasks: list<array>}>}>, to_schedule: list<array{project: array, tasks: list<array>}>, task_count: int, pending_task_count: int}
     */
    public function agenda(User $user, Carbon $from, Carbon $to, array $filters = [], bool $hidePending = false): array
    {
        $vendorId = (int) $user->vendor->id;
        $userId = (int) $user->id;
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');
        $today = $this->today()->format('Y-m-d');

        $projects = $this->candidateProjects($fromStr, $toStr, $filters)
            ->map(function (Project $project) use ($filters, $userId): Project {
                $project->setRelation(
                    'tasks',
                    $project->tasks->filter(fn (Task $task): bool => $this->taskMatchesFilters($task, $project, $filters, $userId))->values(),
                );

                return $project;
            })
            ->filter(fn (Project $project): bool => $project->tasks->isNotEmpty())
            ->values();

        Project::primeLatestVendorStatuses($projects);

        $allTasks = $projects->flatMap(fn (Project $project): Collection => $project->tasks);
        Task::primeAssignedUsers($allTasks);

        $ordered = $projects->sortBy(fn (Project $project): string => $this->projectSortKey($project, $vendorId))->values();

        /** @var array<string, array<int, array{project: Project, tasks: list<Task>}>> $byDate */
        $byDate = [];
        /** @var array<int, array{project: Project, tasks: list<Task>}> $toScheduleByProject */
        $toScheduleByProject = [];

        foreach ($ordered as $project) {
            foreach ($project->tasks as $task) {
                [$firstDate] = $this->taskPlannerDates($task);

                if ($firstDate === null) {
                    $toScheduleByProject[$project->id] ??= ['project' => $project, 'tasks' => []];
                    $toScheduleByProject[$project->id]['tasks'][] = $task;

                    continue;
                }

                foreach ($this->taskBookedDates($task) as $date) {
                    if ($date < $fromStr || $date > $toStr) {
                        continue;
                    }

                    $byDate[$date][$project->id] ??= ['project' => $project, 'tasks' => []];
                    $byDate[$date][$project->id]['tasks'][] = $task;
                }
            }
        }

        $days = collect(CarbonPeriod::create($from, '1 day', $to))
            ->map(function (Carbon $day) use ($byDate, $today, $vendorId): array {
                $date = $day->format('Y-m-d');

                $groups = collect($byDate[$date] ?? [])
                    ->map(fn (array $group): array => [
                        'project' => $this->projectPayload($group['project'], $vendorId),
                        'tasks' => collect($group['tasks'])
                            ->sortBy(fn (Task $task): string => $this->taskSortKey($task, $date))
                            ->values()
                            ->map(fn (Task $task): array => $this->taskPayload($task, $date))
                            ->all(),
                    ])
                    ->values()
                    ->all();

                return [
                    'date' => $date,
                    'is_today' => $date === $today,
                    'is_weekend' => $day->isWeekend(),
                    'groups' => $groups,
                ];
            })
            ->values()
            ->all();

        // mobileTaskCount()/mobileUnscheduledTasks() equivalents: counted
        // from the raw Task objects (before they're reshaped into payload
        // arrays) so a task booked on several visible days is still counted
        // once. $hidePending mirrors mobileUnscheduledTasks() returning
        // empty while viewing Past only.
        $pendingTaskIds = collect($toScheduleByProject)
            ->flatMap(fn (array $group): array => collect($group['tasks'])->map(fn (Task $task): int => $task->id)->all())
            ->unique();

        $scheduledTaskIds = collect($byDate)
            ->flatMap(fn (array $projectGroups): array => collect($projectGroups)->all())
            ->flatMap(fn (array $group): array => collect($group['tasks'])->map(fn (Task $task): int => $task->id)->all())
            ->unique();

        $toSchedule = $hidePending ? [] : collect($toScheduleByProject)
            ->map(fn (array $group): array => [
                'project' => $this->projectPayload($group['project'], $vendorId),
                'tasks' => collect($group['tasks'])->map(fn (Task $task): array => $this->undatedTaskPayload($task))->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'from' => $fromStr,
            'to' => $toStr,
            'today' => $today,
            'days' => $days,
            'to_schedule' => $toSchedule,
            'task_count' => $scheduledTaskIds->merge($hidePending ? collect() : $pendingTaskIds)->unique()->count(),
            'pending_task_count' => $hidePending ? 0 : $pendingTaskIds->count(),
        ];
    }

    /**
     * Open action items across every project visible to $user's vendor,
     * grouped and ranked exactly like CardsIndex::actionItemsByProject()
     * (the signed-in person's own items first, then the crew's, then
     * everyone else's, then unassigned), newest-mentioned project first.
     *
     * @return list<array{project: array{id: int, name: string, short_address: ?string}, recent: int, older: int, groups: list<array{owner: string, items: list<array>}>}>
     */
    public function actionItems(User $user): array
    {
        $me = Str::lower((string) $user->first_name);
        $cutoff = now()->subDays((int) config('action_items.list_days', 14));

        $rank = function (?string $owner) use ($me): string {
            $o = Str::lower((string) $owner);

            return match (true) {
                $o === '' => '3',
                $o === $me => '0',
                in_array($o, ['gs', 'us', 'crew', 'team'], true) || str_starts_with($o, 'gs ') => '1',
                default => '2',
            };
        };

        $items = ProjectActionItem::query()
            ->open()
            ->whereHas('project')
            ->with('project')
            ->orderByDesc('last_mentioned_at')
            ->get();

        return $items->groupBy('project_id')
            ->map(function (Collection $projectItems) use ($rank, $cutoff): array {
                $project = $projectItems->first()->project;

                $groups = $projectItems
                    ->sortBy(fn (ProjectActionItem $item): string => $rank($item->owner).'|'.Str::lower((string) $item->owner).'|'.sprintf('%020d', PHP_INT_MAX - ($item->last_mentioned_at?->getTimestamp() ?? 0)))
                    ->groupBy(fn (ProjectActionItem $item): string => $item->owner ?: 'Unassigned')
                    ->map(fn (Collection $rows, string $owner): array => [
                        'owner' => $owner,
                        'items' => $rows->map(fn (ProjectActionItem $item): array => $this->actionItemPayload($item))->values()->all(),
                    ])
                    ->values()
                    ->all();

                return [
                    'project' => $this->projectSummaryPayload($project),
                    'recent' => $projectItems->filter(fn (ProjectActionItem $item): bool => (bool) ($item->last_mentioned_at && $item->last_mentioned_at->gte($cutoff)))->count(),
                    'older' => $projectItems->filter(fn (ProjectActionItem $item): bool => ! $item->last_mentioned_at || $item->last_mentioned_at->lt($cutoff))->count(),
                    'groups' => $groups,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Mark an action item done or dismissed, like CardsIndex::settleActionItem()
     * — null when the item doesn't exist or its project isn't visible to
     * $user (the controller turns that into a 404).
     */
    public function settle(User $user, int $itemId, string $status): ?ProjectActionItem
    {
        $item = ProjectActionItem::query()->whereKey($itemId)->whereHas('project')->first();

        if (! $item) {
            return null;
        }

        $item->update([
            'status' => $status,
            'done_at' => $status === ProjectActionItem::STATUS_DONE ? now() : $item->done_at,
            'done_evidence' => [
                'kind' => 'user',
                'id' => (int) $user->id,
                'at' => now()->toIso8601String(),
                'excerpt' => $status === ProjectActionItem::STATUS_DONE ? 'Marked done in the app' : 'Marked dismissed in the app',
            ],
        ]);

        return $item;
    }

    /**
     * Projects whose latest status is a planner status, OR which have a
     * task touching [$fromStr, $toStr] — see the class docblock for why
     * this is start_date/end_date only, no JSON function. $filters'
     * project_ids/status_codes narrow this further at the query level;
     * search/vendor_id/user_ids/mine are applied per-task afterwards (see
     * taskMatchesFilters()), since they can match on a task's own fields.
     *
     * @param  array{project_ids?: list<int>, status_codes?: list<int>}  $filters
     */
    private function candidateProjects(string $fromStr, string $toStr, array $filters = []): Collection
    {
        $query = Project::query()
            ->where(function (Builder $query) use ($fromStr, $toStr): void {
                $query->whereHas('latestStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('status_code', CardsIndex::PLANNER_PROJECT_STATUS_CODES))
                    ->orWhereHas('tasks', function (Builder $taskQuery) use ($fromStr, $toStr): void {
                        $taskQuery->where(function (Builder $range) use ($fromStr, $toStr): void {
                            $range->whereBetween('start_date', [$fromStr, $toStr])
                                ->orWhereBetween('end_date', [$fromStr, $toStr])
                                ->orWhere(function (Builder $spans) use ($fromStr, $toStr): void {
                                    $spans->where('start_date', '<=', $toStr)->where('end_date', '>=', $fromStr);
                                });
                        });
                    });
            })
            ->with(['client', 'tasks' => fn ($query) => $query->with('vendor')->orderBy('start_date')]);

        if (! empty($filters['project_ids'])) {
            $query->whereIn('id', $filters['project_ids']);
        }

        if (! empty($filters['status_codes'])) {
            $query->whereHas('latestStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('status_code', $filters['status_codes']));
        }

        return $query->get();
    }

    /**
     * Whether $task (on $project) passes every active filter — AND'd
     * together, same as the web's filter toolbar. An empty $filters value
     * for any key is "no restriction", matching the web's "All ___"
     * placeholder.
     *
     * @param  array{search?: string, vendor_id?: ?int, user_ids?: list<int>, mine?: bool}  $filters
     */
    private function taskMatchesFilters(Task $task, Project $project, array $filters, int $userId): bool
    {
        if (! empty($filters['vendor_id']) && (int) $task->vendor_id !== (int) $filters['vendor_id']) {
            return false;
        }

        $taskUserIds = collect($task->user_ids ?? [])->map(fn ($id): int => (int) $id);

        if (! empty($filters['user_ids']) && $taskUserIds->intersect($filters['user_ids'])->isEmpty()) {
            return false;
        }

        if (! empty($filters['mine']) && ! $taskUserIds->contains($userId)) {
            return false;
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $needle = Str::lower($search);

            $projectMatches = Str::contains(Str::lower((string) $project->project_name), $needle)
                || Str::contains(Str::lower((string) $project->short_address), $needle)
                || Str::contains(Str::lower((string) ($project->client?->name ?? '')), $needle);

            $taskMatches = Str::contains(Str::lower((string) $task->title), $needle)
                || Str::contains(Str::lower((string) ($task->vendor?->name ?? '')), $needle);

            if (! $projectMatches && ! $taskMatches) {
                return false;
            }
        }

        return true;
    }

    /** Same card order as CardsIndex::activeProjects(): status priority, then status start date, then address. */
    private function projectSortKey(Project $project, int $vendorId): string
    {
        $status = $project->latestVendorStatus($vendorId);
        $priority = self::STATUS_PRIORITY[$status?->status_code ?? 0] ?? 999;
        $startDate = $status?->start_date?->format('Y-m-d') ?: '9999-12-31';

        return str_pad((string) $priority, 3, '0', STR_PAD_LEFT).'-'.$startDate.'-'.mb_strtolower((string) ($project->short_address ?? ''));
    }

    /**
     * Mirrors CardsIndex::taskPlannerDates(): options.dates first, else the
     * legacy start/end columns, else undated.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function taskPlannerDates(Task $task): array
    {
        $dates = collect($task->options->dates ?? [])
            ->filter()
            ->map(fn ($date): string => (string) $date)
            ->sort()
            ->values();

        if ($dates->isNotEmpty()) {
            return [$dates->first(), $dates->last()];
        }

        $start = $task->start_date?->format('Y-m-d');
        $end = $task->end_date?->format('Y-m-d');

        if ($start || $end) {
            return [$start ?? $end, $end ?? $start];
        }

        return [null, null];
    }

    /**
     * Mirrors CardsIndex::taskBookedDates(): every day picked in
     * options.dates, else every day from start to end (capped).
     *
     * @return array<int, string>
     */
    private function taskBookedDates(Task $task): array
    {
        $picked = collect($task->options->dates ?? [])
            ->filter()
            ->map(fn ($date): string => substr((string) $date, 0, 10))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($picked !== []) {
            return $picked;
        }

        [$first, $last] = $this->taskPlannerDates($task);

        if ($first === null) {
            return [];
        }

        $dates = [];
        $cursor = Carbon::createFromFormat('!Y-m-d', $first);
        $stop = Carbon::createFromFormat('!Y-m-d', $last ?? $first);

        for ($guard = 0; $cursor->lte($stop) && $guard < 120; $guard++) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }

    /** Mirrors PlannerTaskCard::dayCounter(): position within the FULL picked-dates list, not the requested window. */
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

    /** Mirrors CardsIndex::agendaDays()'s per-day task sort: timed tasks first by start time, then by title. */
    private function taskSortKey(Task $task, string $date): string
    {
        $startTime = (string) data_get($task->options, "time_settings.$date.start_time", '');
        $usesTime = (bool) data_get($task->options, "time_settings.$date.use_time", false);

        return $usesTime && $startTime !== '' ? '0_'.$startTime : '1_'.mb_strtolower((string) $task->title);
    }

    /**
     * @return array{id: int, name: ?string, short_address: ?string, status: ?array{code: int, title: string, color: string}, subtitle: ?string}
     */
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
            'subtitle' => $this->projectSubtitle($project),
        ];
    }

    /**
     * "Abbey | Kitchen" — the client's last name(s) and the project name,
     * joined like the web agenda's card
     * (resources/views/livewire/planner/_agenda.blade.php:
     * `$project->client?->last_names` ' | ' `$project->project_name`); null
     * when neither is set.
     */
    private function projectSubtitle(Project $project): ?string
    {
        $lastNames = trim((string) ($project->client?->last_names ?? ''));
        $name = trim((string) ($project->project_name ?? ''));

        if ($lastNames === '' && $name === '') {
            return null;
        }

        return $lastNames !== '' && $name !== '' ? "{$lastNames} | {$name}" : ($lastNames ?: $name);
    }

    /** @return array{id: int, name: ?string, short_address: ?string} */
    private function projectSummaryPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->project_name,
            'short_address' => $project->short_address,
        ];
    }

    /**
     * @return array{id: int, title: string, type: ?string, arrival: ?string, day_counter: ?array{current: int, total: int}, progress: ?int, notes: ?string, vendor: ?string, users: list<string>, is_meet: bool, checklist_done: int, checklist_total: int}
     */
    private function taskPayload(Task $task, string $date): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'arrival' => $task->getArrivalTimeLabel($date),
            'day_counter' => $this->dayCounter($task, $date),
            'progress' => $task->progress !== null ? (int) $task->progress : null,
            'notes' => $task->notes,
            'vendor' => $task->vendor?->name,
            'users' => $this->taskUserNames($task),
            'is_meet' => $task->type === 'Meet',
            ...$this->checklistCounts($task),
        ];
    }

    /** @return array{id: int, title: string, type: ?string, vendor: ?string, users: list<string>, checklist_done: int, checklist_total: int} */
    private function undatedTaskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'vendor' => $task->vendor?->name,
            'users' => $this->taskUserNames($task),
            ...$this->checklistCounts($task),
        ];
    }

    /**
     * How many of a task's checklist boxes are ticked — the same "X/Y
     * to-dos" count the web's compact planner card shows
     * (resources/views/components/upcoming-tasks-list-card-content.blade.php's
     * $compactChecklist), lighter than the full interactive list the Home
     * tab sends (App\Services\Dashboard\AppHomeAgenda::checklistPayload()):
     * the planner's agenda can carry many tasks at once, so this sends only
     * the two counts rather than every item's text.
     *
     * @return array{checklist_done: int, checklist_total: int}
     */
    private function checklistCounts(Task $task): array
    {
        $items = collect((array) data_get($task->options, 'checklist', []))
            ->map(fn ($item) => is_scalar($item) ? ['text' => $item] : (array) $item)
            ->filter(fn (array $item): bool => trim((string) ($item['text'] ?? '')) !== '');

        return [
            'checklist_done' => $items->where('completed', true)->count(),
            'checklist_total' => $items->count(),
        ];
    }

    /**
     * Nickname-or-first-name only, same as MessageResource's sender name —
     * the app's agenda cards have no room for an avatar, just a short name.
     *
     * @return list<string>
     */
    private function taskUserNames(Task $task): array
    {
        return $task->users
            ->map(fn (User $user): string => trim((string) ($user->nickname ?: $user->first_name)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string, owner: ?string, due_on: ?string, start_time: ?string, end_time: ?string, mentions: int, last_mentioned_at: ?string, sources: list<array{kind: ?string, label: string, excerpt: string, url: ?string}>}
     */
    private function actionItemPayload(ProjectActionItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'owner' => $item->owner,
            'due_on' => $item->due_on?->toDateString(),
            // The visit's time ("11:00"–"13:00"), for the task it becomes.
            'start_time' => $item->start_time,
            'end_time' => $item->end_time,
            'mentions' => (int) $item->mentions,
            'last_mentioned_at' => $item->last_mentioned_at?->toIso8601String(),
            // 'kind' rides alongside the human label so the app can pick an
            // icon (call/sms/email — see ProjectActionItem::sourceLabel())
            // without having to parse the label string back apart.
            'sources' => collect($item->sources ?? [])
                ->map(fn (array $source): array => [
                    'kind' => $source['kind'] ?? null,
                    'label' => ProjectActionItem::sourceLabel($source),
                    'excerpt' => (string) ($source['excerpt'] ?? ''),
                    'url' => ProjectActionItem::sourceUrl($source),
                ])
                ->values()
                ->all(),
        ];
    }
}
