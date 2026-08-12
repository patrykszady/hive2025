<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectStatus;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

// #[Lazy]
class CardsIndex extends Component
{
    use \App\Livewire\Concerns\TogglesTaskChecklist;

    public $vendors = [];
    public $employees = [];
    public $projects = [];

    // View mode: 'cards' | 'table' | 'gantt'
    #[Url(as: 'view')]
    public string $viewMode = 'table';

    /** Gantt zoom: 'day' (80px/day), 'week' (32px/day), 'month' (14px/day). */
    #[Url(as: 'zoom')]
    public string $ganttZoom = 'day';

    /** Pixels per day per zoom level. Single source of truth for layout math (PHP + Alpine). */
    public const GANTT_PX_PER_DAY = [
        'day' => 140,
        'week' => 80,
        'month' => 32,
    ];

    // Filter properties
    public array $filterProjectIds = [];
    public ?int $filterVendorId = null;
    public array $filterUserIds = [];

    /** Default Status filter: Active, Service Call, Prep, and Scheduled projects (display order set by PLANNER_STATUS_PRIORITY). */
    public const DEFAULT_STATUS_CODES = [4, 5, 6, 8];
    public array $filterStatusCodes = self::DEFAULT_STATUS_CODES;

    /** List view time window: 'upcoming' (today + future, default), 'past', or 'all'. */
    #[Url(as: 'when')]
    public string $filterDateRange = 'upcoming';

    public bool $showMobileFilters = false;

    public int $previousDaysLoaded = 30;
    public int $futureDaysLoaded = 60;

    private const DAYS_PER_LOAD = 30;

    public function toggleMobileFilters(): void
    {
        $this->showMobileFilters = ! $this->showMobileFilters;
    }

    public function loadPreviousDays(): void
    {
        $this->previousDaysLoaded += self::DAYS_PER_LOAD;
    }

    public function loadFutureDays(): void
    {
        $this->futureDaysLoaded += self::DAYS_PER_LOAD;
    }

    private const PLANNER_PROJECT_STATUS_CODES = [4, 5, 6, 8]; // Prep, Scheduled, Active, Service Call
    private const PLANNER_STATUS_PRIORITY = [6 => 1, 8 => 2, 4 => 3, 5 => 4]; // Active, Service Call, Prep, Scheduled

    protected $listeners = [
        'refreshComponent' => '$refresh',
        'dependenciesUpdated' => 'onDependenciesUpdated',
    ];

    /**
     * Lightweight refresh when only task dependencies changed (e.g. from edit-task modal).
     * Only the gantt arrows depend on dep data; in other views skip the round-trip entirely.
     */
    public function onDependenciesUpdated(): void
    {
        if ($this->viewMode !== 'gantt') {
            $this->skipRender();
            return;
        }

        unset($this->ganttDependencyLinks, $this->ganttArrowPaths, $this->criticalPathTaskIds);
    }

    public function mount()
    {
        $this->employees = auth()->user()->vendor->users()->employed()->get();
        
        // Sort vendors by ytd_expense_sum (same as task modal)
        $this->vendors = Vendor::search('*')
            ->orderBy('ytd_expense_sum', 'desc')
            ->get();
        
        $this->projects = Project::status(self::PLANNER_PROJECT_STATUS_CODES)
            ->with('latestStatus')
            ->get()
            ->sortBy(function (Project $project): string {
                $priority = self::PLANNER_STATUS_PRIORITY[$project->latestStatus->status_code ?? 0] ?? 999;
                $startDate = ($project->latestStatus?->start_date?->format('Y-m-d')) ?: '9999-12-31';

                return str_pad((string) $priority, 3, '0', STR_PAD_LEFT)
                    .'-'.$startDate
                    .'-'.mb_strtolower((string) ($project->short_address ?? ''));
            })
            ->values();
    }

    /**
     * Get days range based on loaded days
     */
    #[Computed]
    public function days()
    {
        $today = browser_today();
        $startDate = $today->copy()->subDays($this->previousDaysLoaded);
        $endDate = $today->copy()->addDays($this->futureDaysLoaded - 1);

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    /**
     * Get available status options for filter (same format as project index)
     */
    #[Computed]
    public function statusOptions()
    {
        return collect(ProjectStatus::selectableStatuses());
    }

    /**
     * Clear all filters
     */
    public function clearFilters()
    {
        $this->filterProjectIds = [];
        $this->filterVendorId = null;
        $this->filterUserIds = [];
        $this->filterStatusCodes = self::DEFAULT_STATUS_CODES;
        $this->filterDateRange = 'upcoming';
    }

    /**
     * Check if any filters are active
     */
    #[Computed]
    public function hasActiveFilters()
    {
        $statusCodes = $this->filterStatusCodes;
        sort($statusCodes);

        return !empty($this->filterProjectIds)
            || $this->filterVendorId !== null
            || !empty($this->filterUserIds)
            || $statusCodes !== self::DEFAULT_STATUS_CODES
            || $this->filterDateRange !== 'upcoming';
    }

    /**
     * Resolve the first and last planner dates for a task.
     *
     * Prefers the `options.dates` array (the current way tasks store the days
     * they appear on) and falls back to the legacy start/end date columns.
     *
     * @return array{0: ?string, 1: ?string} [firstDate, lastDate] as Y-m-d strings, or [null, null] when undated.
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
     * Flat list of tasks (across the filtered projects) for the "list" view.
     *
     * Reuses the same filtering as the other planner views via `activeProjects`,
     * then flattens every project's tasks into a single sortable collection with
     * pre-resolved dates and assignees (assignees are batch-loaded to avoid N+1).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    #[Computed]
    public function taskList()
    {
        $tasks = $this->activeProjects
            ->flatMap(fn (Project $project) => $project->tasks->map(fn (Task $task) => [$project, $task]))
            ->reject(fn (array $pair) => $pair[1]->trashed());

        $userMap = User::whereIn('id', $tasks->flatMap(fn (array $pair) => $pair[1]->user_ids ?? [])->unique()->values())
            ->get()
            ->keyBy('id');

        $today = browser_today()->format('Y-m-d');

        return $tasks
            ->map(function (array $pair) use ($userMap): object {
                [$project, $task] = $pair;
                [$firstDate, $lastDate] = $this->taskPlannerDates($task);

                return (object) [
                    'task' => $task,
                    'project' => $project,
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                    'users' => collect($task->user_ids ?? [])
                        ->map(fn ($id) => $userMap->get((int) $id))
                        ->filter()
                        ->values(),
                ];
            })
            ->filter(function (object $row) use ($today): bool {
                return match ($this->filterDateRange) {
                    // Tasks that fully ended before today (undated tasks are excluded).
                    'past' => $row->last_date !== null && $row->last_date < $today,
                    // Today + future, plus undated tasks that still need scheduling.
                    'upcoming' => $row->last_date === null || $row->last_date >= $today,
                    default => true,
                };
            })
            ->sortBy(fn (object $row): string => ($row->first_date ?? '9999-12-31')
                .'-'.str_pad((string) $row->task->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * [project, task] pairs from the filtered project set, with `users` and
     * `project` relations resolved. Shared source for the mobile agenda views.
     *
     * @return \Illuminate\Support\Collection<int, array{0: Project, 1: Task}>
     */
    #[Computed]
    public function mobileTaskPairs(): Collection
    {
        $pairs = $this->activeProjects
            ->flatMap(fn (Project $project) => $project->tasks->map(fn (Task $task) => [$project, $task]))
            ->reject(fn (array $pair) => $pair[1]->trashed())
            ->values();

        $userMap = User::whereIn('id', $pairs->flatMap(fn (array $pair) => $pair[1]->user_ids ?? [])->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($pairs as [$project, $task]) {
            $task->setRelation('project', $project);
            $task->setRelation('users', collect($task->user_ids ?? [])
                ->map(fn ($id) => $userMap->get((int) $id))
                ->filter()
                ->values());
        }

        return $pairs;
    }

    /**
     * Tasks grouped by date (Y-m-d) for the shared `upcoming-tasks-list` component
     * used in the mobile agenda. Expands each task across its selected dates and
     * honours the active date-range filter, bounded to the visible window.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function mobileGroupedTasks(): Collection
    {
        $today = browser_today()->format('Y-m-d');
        $windowStart = $this->days->first()->format('Y-m-d');
        $windowEnd = $this->days->last()->format('Y-m-d');

        $grouped = collect();

        foreach ($this->mobileTaskPairs as [$project, $task]) {
            [$firstDate] = $this->taskPlannerDates($task);

            // Undated tasks are surfaced separately via mobileUnscheduledTasks().
            if ($firstDate === null) {
                continue;
            }

            $dates = collect($task->options->dates ?? [])
                ->filter()
                ->map(fn ($date): string => (string) $date);

            if ($dates->isEmpty()) {
                $dates = collect([$firstDate]);
            }

            foreach ($dates->unique() as $dateStr) {
                if ($dateStr < $windowStart || $dateStr > $windowEnd) {
                    continue;
                }

                $inRange = match ($this->filterDateRange) {
                    'past' => $dateStr < $today,
                    'upcoming' => $dateStr >= $today,
                    default => true,
                };

                if (! $inRange) {
                    continue;
                }

                if (! $grouped->has($dateStr)) {
                    $grouped[$dateStr] = collect();
                }

                $grouped[$dateStr]->push($task);
            }
        }

        return $grouped
            ->sortKeys()
            ->map(fn (Collection $tasks, string $dateStr) => $tasks
                ->sortBy(function (Task $task) use ($dateStr): string {
                    $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                    $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);

                    return $usesTime && $startTime !== '' ? '0_'.$startTime : '1';
                })
                ->values());
    }

    /**
     * Undated (pending) tasks across the filtered project set, for the mobile
     * agenda's "Pending Tasks" section. Hidden when viewing past tasks only.
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    #[Computed]
    public function mobileUnscheduledTasks(): Collection
    {
        if ($this->filterDateRange === 'past') {
            return collect();
        }

        return $this->mobileTaskPairs
            ->filter(fn (array $pair) => $this->taskPlannerDates($pair[1])[0] === null)
            ->map(fn (array $pair) => $pair[1])
            ->values();
    }

    /**
     * Distinct task count for the mobile agenda badge.
     */
    #[Computed]
    public function mobileTaskCount(): int
    {
        return $this->mobileGroupedTasks->flatten()->pluck('id')
            ->merge($this->mobileUnscheduledTasks->pluck('id'))
            ->unique()
            ->count();
    }

    /**
     * Get active projects with their tasks
     */
    #[Computed]
    public function activeProjects()
    {
        // Get the visible date range
        $startDate = $this->days->first()->format('Y-m-d');
        $endDate = $this->days->last()->format('Y-m-d');

        // Build base query: projects with planner statuses OR projects with tasks in the visible date range
        $query = Project::query()
            ->where(function ($q) use ($startDate, $endDate) {
                // Include projects with planner statuses
                $q->whereHas('latestStatus', function ($statusQuery) {
                    $statusQuery->whereIn('status_code', self::PLANNER_PROJECT_STATUS_CODES);
                })
                // OR include projects that have tasks in the visible date range (regardless of status)
                ->orWhereHas('tasks', function ($taskQuery) use ($startDate, $endDate) {
                    $taskQuery->where(function ($tq) use ($startDate, $endDate) {
                        // Tasks with selected dates in the visible range
                        $tq->whereRaw("JSON_OVERLAPS(JSON_EXTRACT(options, '$.dates'), ?)", [
                            json_encode($this->days->map->format('Y-m-d')->values()->toArray())
                        ])
                        // OR tasks with start_date in range
                        ->orWhereBetween('start_date', [$startDate, $endDate])
                        // OR tasks spanning the range
                        ->orWhere(function ($rangeQ) use ($startDate, $endDate) {
                            $rangeQ->where('start_date', '<=', $endDate)
                                   ->where('end_date', '>=', $startDate);
                        });
                    });
                });
            })
            ->with(['tasks' => function ($query) {
                $taskQuery = $query
                    ->withTrashed()
                    ->with(['vendor'])
                    ->orderByRaw('start_date is null')
                    ->orderBy('start_date');
                
                // Filter tasks by vendor if set
                if ($this->filterVendorId) {
                    $taskQuery->where('vendor_id', $this->filterVendorId);
                }
                
                // Filter tasks by user(s) if set
                if (!empty($this->filterUserIds)) {
                    $taskQuery->where(function ($q) {
                        foreach ($this->filterUserIds as $userId) {
                            // user_ids are stored as strings in JSON
                            $q->orWhereJsonContains('user_ids', (string) $userId);
                        }
                    });
                }
            }, 'client.users', 'latestStatus'])
            ->orderBy('address');
        
        // Filter by specific projects (multi-select)
        if (!empty($this->filterProjectIds)) {
            $query->whereIn('id', $this->filterProjectIds);
        }
        
        // Filter by status codes (multi-select)
        if (!empty($this->filterStatusCodes)) {
            $query->whereHas('latestStatus', function ($q) {
                $q->whereIn('status_code', $this->filterStatusCodes);
            });
        }
        
        return $query->get()
            ->sortBy(function (Project $project): string {
                $priority = self::PLANNER_STATUS_PRIORITY[$project->latestStatus->status_code ?? 0] ?? 999;
                $startDate = ($project->latestStatus?->start_date?->format('Y-m-d')) ?: '9999-12-31';

                return str_pad((string) $priority, 3, '0', STR_PAD_LEFT)
                    .'-'.$startDate
                    .'-'.mb_strtolower((string) ($project->short_address ?? ''));
            })
            ->values();
    }

    /**
     * Get kanban data - days with all active projects showing tasks for each day
     */
    #[Computed]
    public function kanbanColumns()
    {
        $today = browser_today();

        $tomorrow = $today->copy()->addDay();

        return $this->days->map(function ($day, $dayIndex) use ($today, $tomorrow) {
            $dayFormat = $day->format('Y-m-d');

            // Show ALL active projects in each day column
            $projectColumns = $this->activeProjects->map(function ($project) use ($day, $dayFormat, $dayIndex, $today) {
                $undatedTasksCount = $project->tasks
                    ->filter(function ($task) {
                        $selectedDates = $task->options->dates ?? [];

                        return empty($selectedDates) && !$task->start_date && !$task->end_date;
                    })
                    ->count();

                // Filter tasks that fall on this specific day
                $dayTasks = $project->tasks->filter(function ($task) use ($dayFormat, $day) {
                    // Check if this specific date is in the selected dates array
                    $selectedDates = $task->options->dates ?? [];
                    
                    if (!empty($selectedDates)) {
                        // New way: check if day is in selected dates
                        return in_array($dayFormat, $selectedDates);
                    } else {
                        // If task has no dates at all, don't show in any day column
                        if (!$task->start_date && !$task->end_date) {
                            return false;
                        }

                        // If only one legacy date is present, treat it as a single-day task.
                        if ($task->start_date && !$task->end_date) {
                            return Carbon::parse($task->start_date)->format('Y-m-d') === $dayFormat;
                        }

                        if (!$task->start_date && $task->end_date) {
                            return Carbon::parse($task->end_date)->format('Y-m-d') === $dayFormat;
                        }

                        // Legacy fallback: use start/end date range with weekend checks
                        $taskStart = Carbon::parse($task->start_date)->format('Y-m-d');
                        $taskEnd = Carbon::parse($task->end_date)->format('Y-m-d');

                        // Task should show if the day falls between start and end (inclusive)
                        $taskSpansDay = $dayFormat >= $taskStart && $dayFormat <= $taskEnd;

                        if (!$taskSpansDay) {
                            return false;
                        }

                        // Check weekend settings
                        if ($day->isWeekend()) {
                            $taskOptions = $task->options ?? (object)[];

                            if ($day->isSaturday() && !($taskOptions->saturday ?? false)) {
                                return false;
                            }

                            if ($day->isSunday() && !($taskOptions->sunday ?? false)) {
                                return false;
                            }
                        }

                        return true;
                    }
                })->values();

                // Sort tasks by start_time: tasks with time first (earliest to latest), then tasks without
                $dayTasks = $dayTasks->sortBy(function ($task) use ($dayFormat) {
                    $startTime = (string) data_get($task->options, "time_settings.$dayFormat.start_time", '');
                    $usesTime = (bool) data_get($task->options, "time_settings.$dayFormat.use_time", false);
                    $hasTime = $usesTime && $startTime !== '';

                    return $hasTime ? '0_' . $startTime : '1';
                })->values();

                // Calculate next/last task info for this project relative to this specific day
                $taskGapInfo = null;
                if ($dayTasks->isEmpty()) {
                    $taskGapInfo = $this->calculateTaskGapInfo($project, $day);
                }

                // Return project with tasks (even if no tasks for this day)
                return (object) [
                    'id' => $project->id,
                    'title' => $project->short_address,
                    'project' => $project,
                    'cards' => $dayTasks,
                    'undated_tasks_count' => $undatedTasksCount,
                    'task_gap_info' => $taskGapInfo,
                ];
            });
            // Note: No longer filtering here - all projects stay to maintain grid alignment

            return (object) [
                'day' => $day,
                'dayIndex' => $dayIndex,
                'title' => $day->format('D, M j'),
                'isToday' => $day->isSameDay($today),
                'isTomorrow' => $day->isSameDay($tomorrow),
                'isWeekend' => $day->isWeekend(),
                'columns' => $projectColumns,
            ];
        })->values();
    }

    /**
     * Pack a project row's per-day task cards into lane rows for the table view.
     *
     * Each unique task that appears across one or more visible day cells is grouped
     * into runs of consecutive day indexes (a "segment"). Segments are then placed
     * into lanes greedily — a segment falls into the first lane whose previous
     * segment has already ended, otherwise a new lane is created. This lets a
     * multi-day task render as a single bar (colspan = number of consecutive days)
     * while overlapping tasks stack onto extra rows beneath the project sidebar.
     *
     * The returned structure is consumed directly by the table blade:
     *   array<int, array<int, object|string|null>>
     * Each inner array has one entry per visible day. Entries are one of:
     *   - object{ type: 'segment', task, span, first_day_format, is_weekend, is_today }
     *   - 'covered' — skipped because an earlier colspan absorbs this column
     *   - object{ type: 'empty', cell } — an empty clickable day cell
     *
     * @return array<int, array<int, object|string>>
     */
    protected function buildLaneRows($dayCells): array
    {
        $dayCount = $dayCells->count();

        // Build map: task_id => ['task' => Task, 'indexes' => [int,...]]
        $taskAppearances = [];
        foreach ($dayCells as $index => $cell) {
            foreach ($cell->cards as $task) {
                if (!isset($taskAppearances[$task->id])) {
                    $taskAppearances[$task->id] = ['task' => $task, 'indexes' => []];
                }
                $taskAppearances[$task->id]['indexes'][] = $index;
            }
        }

        // Flatten into segments of consecutive day indexes.
        $segments = [];
        foreach ($taskAppearances as $entry) {
            $indexes = $entry['indexes'];
            sort($indexes);
            $runStart = $indexes[0];
            $prev     = $indexes[0];
            $count    = count($indexes);
            for ($i = 1; $i < $count; $i++) {
                if ($indexes[$i] === $prev + 1) {
                    $prev = $indexes[$i];
                    continue;
                }
                $segments[] = ['task' => $entry['task'], 'start' => $runStart, 'span' => $prev - $runStart + 1];
                $runStart = $indexes[$i];
                $prev     = $indexes[$i];
            }
            $segments[] = ['task' => $entry['task'], 'start' => $runStart, 'span' => $prev - $runStart + 1];
        }

        // Sort segments: earliest start first, longest span first for tie-breaks
        // (so wider bars settle into earlier lanes for a tidier look).
        usort($segments, fn($a, $b) => $a['start'] <=> $b['start'] ?: $b['span'] <=> $a['span']);

        // Greedy lane packing.
        /** @var array<int, array{end:int, segments:array}> $lanes */
        $lanes = [];
        foreach ($segments as $seg) {
            $placed = false;
            foreach ($lanes as &$lane) {
                if ($lane['end'] < $seg['start']) {
                    $lane['segments'][] = $seg;
                    $lane['end']        = $seg['start'] + $seg['span'] - 1;
                    $placed             = true;
                    break;
                }
            }
            unset($lane);
            if (!$placed) {
                $lanes[] = ['end' => $seg['start'] + $seg['span'] - 1, 'segments' => [$seg]];
            }
        }

        // Always render at least one lane so empty projects keep a clickable row.
        if (empty($lanes)) {
            $lanes = [['end' => -1, 'segments' => []]];
        }

        // Materialize each lane as a per-day entry array.
        $laneRows = [];
        foreach ($lanes as $lane) {
            $entries = array_fill(0, $dayCount, null);
            foreach ($lane['segments'] as $seg) {
                $startCell = $dayCells[$seg['start']];

                // Capture weekend/today flags for every day the segment covers so
                // the table view can paint the correct background per column even
                // though the segment renders as a single td with colspan.
                $dayFlags = [];
                for ($i = $seg['start']; $i < $seg['start'] + $seg['span']; $i++) {
                    $cell = $dayCells[$i];
                    $dayFlags[] = (object) [
                        'is_weekend' => $cell->isWeekend,
                        'is_today'   => $cell->isToday,
                    ];
                }

                $entries[$seg['start']] = (object) [
                    'type'             => 'segment',
                    'task'             => $seg['task'],
                    'span'             => $seg['span'],
                    'first_day_format' => $startCell->dayFormat,
                    'is_weekend'       => $startCell->isWeekend,
                    'is_today'         => $startCell->isToday,
                    'day_flags'        => $dayFlags,
                ];
                for ($i = $seg['start'] + 1; $i < $seg['start'] + $seg['span']; $i++) {
                    $entries[$i] = 'covered';
                }
            }
            foreach ($entries as $i => $entry) {
                if ($entry === null) {
                    $entries[$i] = (object) ['type' => 'empty', 'cell' => $dayCells[$i]];
                }
            }
            $laneRows[] = $entries;
        }

        return $laneRows;
    }

    /**
     * Get project rows for grid-based layout
     * Structure: projects as rows, each with cells for each visible day
     */
    #[Computed]
    public function projectRows()
    {
        $today = browser_today();
        $tomorrow = $today->copy()->addDay();

        return $this->activeProjects->map(function ($project) use ($today, $tomorrow) {
            $projectTasks = $project->tasks;

            // Get undated tasks count for this project
            $undatedTasksCount = $projectTasks->filter(function ($task) {
                $selectedDates = $task->options->dates ?? [];
                return empty($selectedDates) && is_null($task->start_date);
            })->count();

            // Map each day to a cell for this project
            $dayCells = $this->days->map(function ($day, $dayIndex) use ($project, $projectTasks, $today, $tomorrow, $undatedTasksCount) {
                $dayFormat = $day->format('Y-m-d');

                // Filter tasks for this day
                $dayTasks = $projectTasks->filter(function ($task) use ($day, $dayFormat) {
                    $selectedDates = $task->options->dates ?? [];

                    // If task has specific selected dates, check if this day is one of them
                    if (!empty($selectedDates)) {
                        return in_array($dayFormat, $selectedDates);
                    }

                    // If task has a start_date, check if it matches this day
                    if ($task->start_date) {
                        $taskStartDate = Carbon::parse($task->start_date);
                        $taskOptions = $task->options;

                        if (!$taskStartDate->isSameDay($day)) {
                            return false;
                        }

                        if ($day->isSaturday() && !($taskOptions->saturday ?? false)) {
                            return false;
                        }

                        if ($day->isSunday() && !($taskOptions->sunday ?? false)) {
                            return false;
                        }

                        return true;
                    }

                    // Task has no dates and no start_date - it's an undated task, don't show in day cells
                    return false;
                })->values();

                // Sort tasks by start_time: tasks with time first (earliest to latest), then tasks without
                $dayTasks = $dayTasks->sortBy(function ($task) use ($dayFormat) {
                    $startTime = (string) data_get($task->options, "time_settings.$dayFormat.start_time", '');
                    $usesTime = (bool) data_get($task->options, "time_settings.$dayFormat.use_time", false);
                    $hasTime = $usesTime && $startTime !== '';

                    return $hasTime ? '0_' . $startTime : '1';
                })->values();

                // Calculate next/last task info for this project on this day
                $taskGapInfo = null;
                if ($dayTasks->isEmpty()) {
                    $taskGapInfo = $this->calculateTaskGapInfo($project, $day);
                }

                return (object) [
                    'day' => $day,
                    'dayIndex' => $dayIndex,
                    'dayFormat' => $dayFormat,
                    'dayTitle' => $day->format('D, M j'),
                    'isToday' => $day->isSameDay($today),
                    'isTomorrow' => $day->isSameDay($tomorrow),
                    'isWeekend' => $day->isWeekend(),
                    'cards' => $dayTasks,
                    'task_gap_info' => $taskGapInfo,
                    'undated_tasks_count' => $undatedTasksCount,
                ];
            })->values();

            // Check if this project has any tasks in the visible day range
            $hasTasksInRange = $dayCells->contains(fn($cell) => $cell->cards->count() > 0);
            
            // Check if project has a planner status (Prep, Scheduled, Active, Service Call)
            $hasPlannerStatus = in_array(
                $project->latestStatus?->status_code ?? 0,
                self::PLANNER_PROJECT_STATUS_CODES
            );

            // Build lane-packed segments for the table view: a multi-day task becomes one
            // bar that spans its consecutive day columns via colspan. Tasks whose date
            // ranges overlap in the same row are pushed onto additional lanes (extra rows).
            $laneRows = $this->buildLaneRows($dayCells);

            return (object) [
                'id' => $project->id,
                'title' => $project->short_address,
                'project' => $project,
                'undated_tasks_count' => $undatedTasksCount,
                'hasTasksInRange' => $hasTasksInRange,
                'hasPlannerStatus' => $hasPlannerStatus,
                'dayCells' => $dayCells,
                'laneRows' => $laneRows,
            ];
        })
        // Filter: only show projects with planner status OR tasks visible in range
        ->filter(fn($row) => $row->hasPlannerStatus || $row->hasTasksInRange)
        ->values();
    }

    /**
     * Get day headers for the grid layout
     */
    #[Computed]
    public function dayHeaders()
    {
        $today = browser_today();
        $tomorrow = $today->copy()->addDay();

        return $this->days->map(function ($day, $index) use ($today, $tomorrow) {
            return (object) [
                'day' => $day,
                'dayIndex' => $index,
                'title' => $day->format('D, M j'),
                'isToday' => $day->isSameDay($today),
                'isTomorrow' => $day->isSameDay($tomorrow),
                'isWeekend' => $day->isWeekend(),
            ];
        })->values();
    }

    /**
     * Calculate task gap info for a project on a given day
     * Returns info about next upcoming task or last past task
     */
    private function calculateTaskGapInfo($project, Carbon $currentDay): ?object
    {
        $currentDayFormat = $currentDay->copy()->startOfDay()->format('Y-m-d');
        
        // Get all tasks for this project (unfiltered - we need ALL tasks to calculate gaps correctly)
        // The $project->tasks relationship may be filtered by vendor/user, so we query directly
        $allTasks = \App\Models\Task::withTrashed()->where('project_id', $project->id)->get();
        
        // Collect all task dates from all tasks
        $allTaskDates = collect();
        
        foreach ($allTasks as $task) {
            $selectedDates = $task->options->dates ?? [];
            
            if (!empty($selectedDates)) {
                foreach ($selectedDates as $date) {
                    $allTaskDates->push($date);
                }
            } elseif ($task->start_date) {
                // Fallback to start_date if no selected dates
                $allTaskDates->push(Carbon::parse($task->start_date)->format('Y-m-d'));
            }
        }
        
        $allTaskDates = $allTaskDates->unique()->sort()->values();
        
        if ($allTaskDates->isEmpty()) {
            return null;
        }
        
        // Find next task date (first date after current day)
        $nextTaskDate = $allTaskDates->filter(fn($date) => $date > $currentDayFormat)->first();
        
        // Find last task date (last date before current day)
        $lastTaskDate = $allTaskDates->filter(fn($date) => $date < $currentDayFormat)->last();
        
        $nextInfo = null;
        $lastInfo = null;
        
        // Calculate next task info if available
        if ($nextTaskDate) {
            $nextDate = Carbon::parse($nextTaskDate)->startOfDay();
            $daysUntil = (int) $currentDay->copy()->startOfDay()->diffInDays($nextDate);
            
            if ($daysUntil > 0) {
                $nextInfo = (object) [
                    'type' => 'next',
                    'days' => $daysUntil,
                    'label' => $daysUntil === 1 ? 'Next tomorrow' : "Next in {$daysUntil} days",
                ];
            }
        }
        
        // Calculate last task info if available
        if ($lastTaskDate) {
            $lastDate = Carbon::parse($lastTaskDate)->startOfDay();
            $daysAgo = (int) $lastDate->diffInDays($currentDay->copy()->startOfDay());
            
            if ($daysAgo > 0) {
                $lastInfo = (object) [
                    'type' => 'last',
                    'days' => $daysAgo,
                    'label' => $daysAgo === 1 ? 'Last yesterday' : "Last {$daysAgo} days ago",
                ];
            }
        }
        
        // Return both if both exist, otherwise whichever one exists
        if ($nextInfo && $lastInfo) {
            return (object) [
                'type' => 'both',
                'next' => $nextInfo,
                'last' => $lastInfo,
            ];
        }
        
        return $nextInfo ?? $lastInfo;
    }

    public function addTask($projectId = null, $date = null)
    {
        $this->dispatch('addTask', $projectId, $date)->to('tasks.task-create');
    }

    // ─── Gantt view ──────────────────────────────────────────

    /** Pixels per day for the current zoom. */
    #[Computed]
    public function ganttPxPerDay(): int
    {
        return self::GANTT_PX_PER_DAY[$this->ganttZoom] ?? self::GANTT_PX_PER_DAY['day'];
    }

    /**
     * Gantt rows: one per project (same filtering as activeProjects),
     * each containing rendered task bars (start/end positioned),
     * unscheduled task badges, and predecessor dependency links.
     */
    #[Computed]
    public function ganttRows()
    {
        $days = $this->days;
        $firstDay = $days->first();
        $lastDay = $days->last();
        $pxPerDay = $this->ganttPxPerDay;
        $criticalIds = $this->criticalPathTaskIds;

        $projects = $this->activeProjects;

        $projectIds = $projects->pluck('id')->all();

        // Eager-load predecessor dependencies for all scheduled tasks in these projects.
        $dependencies = TaskDependency::query()
            ->whereHas('successor', fn($q) => $q->whereIn('project_id', $projectIds))
            ->with(['predecessor:id,project_id,start_date,end_date', 'successor:id,project_id,start_date,end_date'])
            ->get()
            ->groupBy('successor_task_id');

        return $projects->map(function (Project $project) use ($days, $firstDay, $lastDay, $pxPerDay, $criticalIds, $dependencies) {
            // Eager-loaded $project->tasks already applies vendor/user filters.
            $scheduled = $project->tasks
                ->filter(fn(Task $t) => $t->start_date && $t->end_date)
                ->flatMap(fn(Task $t) => $this->buildGanttBarsForTask($t, $firstDay, $lastDay, $pxPerDay, $criticalIds, $dependencies->get($t->id, collect())))
                ->values();

            $unscheduled = $project->tasks
                ->filter(fn(Task $t) => ! $t->start_date || ! $t->end_date)
                ->values();

            // Group bars into rows: keep parent + children on the same row family but
            // separate rows when bars overlap horizontally to avoid visual stacking.
            $rows = $this->packGanttBarsIntoRows($scheduled);

            return (object) [
                'id'           => $project->id,
                'project'      => $project,
                'rows'         => $rows,
                'rowCount'     => count($rows),
                'unscheduled'  => $unscheduled,
                'allBars'      => $scheduled, // for dependency arrow computation
            ];
        })
        ->filter(fn($row) => $row->rows || $row->unscheduled->isNotEmpty())
        ->values();
    }

    /**
     * Flattened dependency list across all visible projects.
     * Used by the SVG arrow layer (predecessor → successor).
     */
    #[Computed]
    public function ganttDependencyLinks(): array
    {
        $visibleBarIds = $this->ganttRows
            ->flatMap(fn($r) => $r->allBars->pluck('task')->pluck('id'))
            ->flip();

        // Build task_id => [start, end] map from the full task (not just visible
        // segment) so violation checks use the real schedule dates.
        $taskDates = [];
        foreach ($this->ganttRows as $row) {
            foreach ($row->allBars as $bar) {
                $t = $bar['task'];
                $taskDates[$t->id] = [
                    'start' => $t->start_date,
                    'end'   => $t->end_date,
                ];
            }
        }

        $links = [];
        foreach ($this->ganttRows as $row) {
            foreach ($row->allBars as $bar) {
                foreach ($bar['dependencies'] as $dep) {
                    // Only render arrows where both endpoints are visible bars.
                    if (! $visibleBarIds->has($dep['predecessor_id'])) {
                        continue;
                    }
                    $succId = $bar['task']->id;
                    $predDates = $taskDates[$dep['predecessor_id']] ?? null;
                    $succDates = $taskDates[$succId] ?? null;

                    $violated = false;
                    if ($predDates && $succDates && $predDates['start'] && $predDates['end'] && $succDates['start'] && $succDates['end']) {
                        $lag = (int) $dep['lag_days'];
                        switch ($dep['type']) {
                            case 'start_to_start':
                                $required = $predDates['start']->copy()->addDays($lag);
                                $violated = $succDates['start']->lt($required);
                                break;
                            case 'finish_to_finish':
                                $required = $predDates['end']->copy()->addDays($lag);
                                $violated = $succDates['end']->lt($required);
                                break;
                            case 'start_to_finish':
                                $required = $predDates['start']->copy()->addDays($lag);
                                $violated = $succDates['end']->lt($required);
                                break;
                            case 'finish_to_start':
                            default:
                                // Successor must start AFTER predecessor ends (+lag).
                                $required = $predDates['end']->copy()->addDays($lag + 1);
                                $violated = $succDates['start']->lt($required);
                                break;
                        }
                    }

                    $links[] = [
                        'predecessor_id' => $dep['predecessor_id'],
                        'successor_id'   => $succId,
                        'type'           => $dep['type'],
                        'lag_days'       => $dep['lag_days'],
                        'is_critical'    => in_array($succId, $this->criticalPathTaskIds, true)
                            && in_array($dep['predecessor_id'], $this->criticalPathTaskIds, true),
                        'is_violated'    => $violated,
                    ];
                }
            }
        }
        return $links;
    }

    /**
     * Pre-computed SVG path strings for every dependency arrow in the visible window.
     * Rendering these server-side eliminates the JS-measurement race that caused
     * arrows to flicker / disappear during morphs.
     *
     * Horizontal segments are routed through row gaps so lines never run across
     * the middle of a task bar.
     *
     * @return array<int, array{d:string,is_violated:bool,is_critical:bool,key:string,predecessor_id:int,successor_id:int}>
     */
    #[Computed]
    public function ganttArrowPaths(): array
    {
        $rows = $this->ganttRows;
        if ($rows->isEmpty()) {
            return [];
        }

        // Layout constants — MUST stay in sync with _gantt.blade.php.
        $pxPerDay           = $this->ganttPxPerDay;
        $rowHeight          = 74;
        $rowPadding         = 6;
        $barHeight          = $rowHeight - 10;
        $projectColumnWidth = 224;
        $headerHeight       = 56;
        $halfGap            = ($rowHeight - $barHeight) / 2; // 5 px

        // Map task_id => list of segment positions (a multi-segment task has >1).
        // gapMidYs collects every horizontal "lane" Y that sits BETWEEN rows so
        // we can snap the connector's middle to a row-gap rather than crossing
        // through bars.
        $taskSegments = [];
        $gapMidYs     = [];
        $cumY = $headerHeight;
        foreach ($rows as $projectRow) {
            $rowsCount = max(1, $projectRow->rowCount);

            // Gap above this project's first row (lane between previous project
            // and this one — or above the very top row).
            $gapMidYs[] = $cumY + ($rowPadding / 2);

            foreach ($projectRow->rows as $rowIndex => $row) {
                $rowTopY = $cumY + $rowPadding + ($rowIndex * $rowHeight);
                $rowBotY = $rowTopY + $barHeight;
                foreach ($row as $bar) {
                    $task       = $bar['task'];
                    $insetLeft  = ($bar['truncated_left']  ?? false) ? 0 : 4;
                    $insetRight = ($bar['truncated_right'] ?? false) ? 0 : 4;
                    $leftX      = $projectColumnWidth + $bar['left_px'] + $insetLeft;
                    $widthPx    = max(8, $bar['width_px'] - $insetLeft - $insetRight);
                    $centerY    = $rowTopY + ($barHeight / 2);

                    $taskSegments[$task->id][] = [
                        'left_x'   => $leftX,
                        'right_x'  => $leftX + $widthPx,
                        'center_y' => $centerY,
                        'top_y'    => $rowTopY,
                        'bot_y'    => $rowBotY,
                    ];
                }
                // Gap below this row (lane between this row and the next one
                // within the same project, or just below the project's last row).
                $gapMidYs[] = $rowBotY + $halfGap;
            }
            $cumY += ($rowsCount * $rowHeight) + (2 * $rowPadding);
        }

        sort($gapMidYs);

        $paths = [];
        foreach ($this->ganttDependencyLinks as $link) {
            $predSegs = $taskSegments[$link['predecessor_id']] ?? null;
            $succSegs = $taskSegments[$link['successor_id']]   ?? null;
            if (! $predSegs || ! $succSegs) {
                continue;
            }

            switch ($link['type']) {
                case 'start_to_start':   $predSide = 'left';  $succSide = 'left';  break;
                case 'finish_to_finish': $predSide = 'right'; $succSide = 'right'; break;
                case 'start_to_finish':  $predSide = 'left';  $succSide = 'right'; break;
                case 'finish_to_start':
                default:                 $predSide = 'right'; $succSide = 'left';  break;
            }

            $predSeg = $this->pickGanttSegment($predSegs, $predSide);
            $succSeg = $this->pickGanttSegment($succSegs, $succSide);

            $x1 = $predSide === 'right' ? $predSeg['right_x'] : $predSeg['left_x'];
            $y1 = $predSeg['center_y'];
            $x2 = $succSide === 'right' ? $succSeg['right_x'] : $succSeg['left_x'];
            $y2 = $succSeg['center_y'];

            // Pick a midY that lies in a row gap. For same-row connections,
            // route in the gap immediately below the predecessor's row.
            if (abs($y1 - $y2) < 1) {
                $midY = $predSeg['bot_y'] + $halfGap;
            } else {
                $loY  = min($y1, $y2);
                $hiY  = max($y1, $y2);
                $midY = ($y1 + $y2) / 2;
                // Snap to the nearest gap-mid that sits strictly between the
                // two bar centers; falls back to halfway if none exists.
                $best     = null;
                $bestDist = PHP_FLOAT_MAX;
                foreach ($gapMidYs as $gy) {
                    if ($gy <= $loY || $gy >= $hiY) {
                        continue;
                    }
                    $dist = abs($gy - $midY);
                    if ($dist < $bestDist) {
                        $bestDist = $dist;
                        $best     = $gy;
                    }
                }
                if ($best !== null) {
                    $midY = $best;
                }
            }

            $exit = 14;
            $sX   = $predSide === 'right' ? $x1 + $exit : $x1 - $exit;
            $tX   = $succSide === 'right' ? $x2 + $exit : $x2 - $exit;

            $paths[] = [
                'd'              => "M {$x1} {$y1} L {$sX} {$y1} L {$sX} {$midY} L {$tX} {$midY} L {$tX} {$y2} L {$x2} {$y2}",
                'is_violated'    => (bool) $link['is_violated'],
                'is_critical'    => (bool) $link['is_critical'],
                'predecessor_id' => $link['predecessor_id'],
                'successor_id'   => $link['successor_id'],
                'key'            => "{$link['predecessor_id']}-{$link['successor_id']}-{$link['type']}",
            ];
        }

        return $paths;
    }

    /**
     * Pick the leftmost or rightmost segment from a list — multi-segment tasks
     * (split across a window boundary) anchor arrows to the outer edges.
     *
     * @param  array<int, array{left_x:float,right_x:float,center_y:float}>  $segs
     */
    private function pickGanttSegment(array $segs, string $side): array
    {
        if (count($segs) === 1) {
            return $segs[0];
        }
        $chosen = $segs[0];
        foreach ($segs as $s) {
            if ($side === 'right' && $s['right_x'] > $chosen['right_x']) {
                $chosen = $s;
            }
            if ($side === 'left' && $s['left_x'] < $chosen['left_x']) {
                $chosen = $s;
            }
        }
        return $chosen;
    }

    /**
     * Compute the critical path: set of task IDs along the longest duration chain
     * through the dependency DAG across all visible projects. Forward + backward pass.
     *
     * @return array<int>
     */
    #[Computed(persist: true, seconds: 300)]
    public function criticalPathTaskIds(): array
    {
        $projectIds = $this->activeProjects->pluck('id')->all();
        if (empty($projectIds)) {
            return [];
        }

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->get(['id', 'start_date', 'end_date']);

        $duration = [];
        foreach ($tasks as $t) {
            $duration[$t->id] = (int) max(1, round($t->start_date->diffInDays($t->end_date)) + 1);
        }

        $deps = TaskDependency::query()
            ->whereIn('predecessor_task_id', array_keys($duration))
            ->whereIn('successor_task_id', array_keys($duration))
            ->get(['predecessor_task_id', 'successor_task_id', 'lag_days']);

        $successors = []; // predecessor_id => [[successor_id, lag], ...]
        $predecessors = []; // successor_id => [[predecessor_id, lag], ...]
        foreach ($deps as $d) {
            $successors[$d->predecessor_task_id][] = [$d->successor_task_id, (int) $d->lag_days];
            $predecessors[$d->successor_task_id][] = [$d->predecessor_task_id, (int) $d->lag_days];
        }

        // Forward pass: earliest finish per task (topological via memoised DFS).
        $earliestFinish = [];
        $earliestFinishFn = function (int $id) use (&$earliestFinishFn, &$earliestFinish, $duration, $predecessors): int {
            if (isset($earliestFinish[$id])) {
                return $earliestFinish[$id];
            }
            $best = $duration[$id] ?? 1;
            foreach ($predecessors[$id] ?? [] as [$predId, $lag]) {
                if (! isset($duration[$predId])) {
                    continue;
                }
                $candidate = $earliestFinishFn($predId) + $lag + ($duration[$id] ?? 1);
                if ($candidate > $best) {
                    $best = $candidate;
                }
            }
            return $earliestFinish[$id] = $best;
        };
        foreach (array_keys($duration) as $id) {
            $earliestFinishFn($id);
        }

        if (empty($earliestFinish)) {
            return [];
        }

        // Identify task(s) ending the critical path (max earliest finish).
        $maxFinish = max($earliestFinish);

        // Backward pass: walk back from each terminal task choosing predecessors
        // that lie on the critical path (earliestFinish[pred] + lag + dur[this] == earliestFinish[this]).
        $critical = [];
        $walk = function (int $id) use (&$walk, &$critical, $earliestFinish, $duration, $predecessors): void {
            if (isset($critical[$id])) {
                return;
            }
            $critical[$id] = true;
            foreach ($predecessors[$id] ?? [] as [$predId, $lag]) {
                if (! isset($earliestFinish[$predId])) {
                    continue;
                }
                if ($earliestFinish[$predId] + $lag + ($duration[$id] ?? 1) === $earliestFinish[$id]) {
                    $walk($predId);
                }
            }
        };
        foreach ($earliestFinish as $id => $ef) {
            if ($ef === $maxFinish) {
                $walk($id);
            }
        }

        return array_keys($critical);
    }

    /**
     * Group a task's selected `options.dates` into runs of consecutive days and
     * emit one bar per run. Tasks without a populated dates array fall back to
     * a single bar spanning start_date..end_date.
     *
     * @return array<int, array>
     */
    private function buildGanttBarsForTask(Task $task, Carbon $firstDay, Carbon $lastDay, int $pxPerDay, array $criticalIds, $taskDeps): array
    {
        $segments = $this->taskDateSegments($task);

        $bars = [];
        foreach ($segments as $index => [$segStart, $segEnd]) {
            $bar = $this->buildGanttBar(
                $task,
                Carbon::parse($segStart)->startOfDay(),
                Carbon::parse($segEnd)->startOfDay(),
                $firstDay,
                $lastDay,
                $pxPerDay,
                $criticalIds,
                $taskDeps,
                $index,
                count($segments),
            );
            if ($bar !== null) {
                $bars[] = $bar;
            }
        }
        return $bars;
    }

    /**
     * Walk $task->options->dates (sorted YYYY-MM-DD strings) and emit
     * [startYmd, endYmd] tuples for each consecutive run.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private function taskDateSegments(Task $task): array
    {
        $raw = $task->options->dates ?? null;
        $dates = [];
        if (is_array($raw) || is_object($raw)) {
            foreach ((array) $raw as $d) {
                if (is_string($d) && $d !== '') {
                    $dates[] = substr($d, 0, 10);
                }
            }
        }

        if (empty($dates)) {
            return [[
                Carbon::parse($task->start_date)->format('Y-m-d'),
                Carbon::parse($task->end_date)->format('Y-m-d'),
            ]];
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        $segments = [];
        $runStart = $dates[0];
        $prev = $dates[0];
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $curr = $dates[$i];
            if (! $this->datesAreContinuous($prev, $curr)) {
                $segments[] = [$runStart, $prev];
                $runStart = $curr;
            }
            $prev = $curr;
        }
        $segments[] = [$runStart, $prev];

        return $segments;
    }

    /**
     * Two YYYY-MM-DD strings are "continuous" if every day strictly between
     * them is a weekend day. This keeps a single bar across Sat/Sun gaps.
     */
    private function datesAreContinuous(string $prev, string $curr): bool
    {
        $cursor = Carbon::parse($prev)->addDay();
        $end = Carbon::parse($curr);
        while ($cursor->lt($end)) {
            if (! $cursor->isWeekend()) {
                return false;
            }
            $cursor->addDay();
        }
        return true;
    }

    /**
     * Build per-bar geometry for a single segment of a task.
     *
     * @return array{
     *     task: Task,
     *     start_date: string,
     *     end_date: string,
     *     left_px: int,
     *     width_px: int,
     *     truncated_left: bool,
     *     truncated_right: bool,
     *     is_critical: bool,
     *     dependencies: array<int, array{predecessor_id:int,type:string,lag_days:int}>,
     *     segment_index: int,
     *     segment_count: int,
     * }|null
     */
    private function buildGanttBar(
        Task $task,
        Carbon $start,
        Carbon $end,
        Carbon $firstDay,
        Carbon $lastDay,
        int $pxPerDay,
        array $criticalIds,
        $taskDeps,
        int $segmentIndex = 0,
        int $segmentCount = 1,
    ): ?array {
        // Skip bars completely outside the visible window.
        if ($end->lt($firstDay) || $start->gt($lastDay)) {
            return null;
        }

        $renderStart = $start->lt($firstDay) ? $firstDay : $start;
        $renderEnd = $end->gt($lastDay) ? $lastDay : $end;

        // Use round() not (int) cast — Carbon 3's diffInDays returns a float,
        // and DST transitions between two startOfDay() values produce e.g. 16.958
        // instead of 17, which truncates to a one-column off-by-one.
        $leftDays = (int) round($firstDay->diffInDays($renderStart));
        $widthDays = (int) round($renderStart->diffInDays($renderEnd)) + 1;

        $depList = [];
        foreach ($taskDeps as $dep) {
            $depList[] = [
                'predecessor_id' => $dep->predecessor_task_id,
                'type'           => $dep->type,
                'lag_days'       => (int) $dep->lag_days,
            ];
        }

        return [
            'task'            => $task,
            'start_date'      => $start->format('Y-m-d'),
            'end_date'        => $end->format('Y-m-d'),
            'left_px'         => $leftDays * $pxPerDay,
            'width_px'        => max($pxPerDay, $widthDays * $pxPerDay),
            'truncated_left'  => $start->lt($firstDay),
            'truncated_right' => $end->gt($lastDay),
            'is_critical'     => in_array($task->id, $criticalIds, true),
            'dependencies'    => $depList,
            'segment_index'   => $segmentIndex,
            'segment_count'   => $segmentCount,
        ];
    }

    /**
     * Greedy row-packing: place each bar in the first row where it does not
     * horizontally overlap an already-placed bar. Sort by start to keep stable.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $bars
     * @return array<int, array<int, array>>
     */
    private function packGanttBarsIntoRows($bars): array
    {
        // Group all bars belonging to the same task so multiple segments of one
        // task always live on the same row.
        $groups = collect($bars)
            ->groupBy(fn($bar) => $bar['task']->id)
            ->map(fn($groupBars) => $groupBars->values()->all())
            ->values()
            ->sortBy(fn($group) => collect($group)->min('left_px'))
            ->values()
            ->all();

        $rows = [];
        foreach ($groups as $group) {
            $placed = false;
            foreach ($rows as &$row) {
                if ($this->groupFitsInRow($group, $row)) {
                    foreach ($group as $bar) {
                        $row[] = $bar;
                    }
                    $placed = true;
                    break;
                }
            }
            unset($row);
            if (! $placed) {
                $rows[] = $group;
            }
        }

        // Keep each row sorted left-to-right for stable rendering.
        foreach ($rows as &$row) {
            usort($row, fn($a, $b) => $a['left_px'] <=> $b['left_px']);
        }
        unset($row);

        return $rows;
    }

    /**
     * A group (all segments of one task) fits in a row if none of its bars
     * horizontally overlap any bar already placed on that row.
     */
    private function groupFitsInRow(array $group, array $row): bool
    {
        foreach ($group as $bar) {
            $barStart = $bar['left_px'];
            $barEnd   = $bar['left_px'] + $bar['width_px'];
            foreach ($row as $existing) {
                $existingStart = $existing['left_px'];
                $existingEnd   = $existing['left_px'] + $existing['width_px'];
                if ($barStart < $existingEnd && $existingStart < $barEnd) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Move/resize a task bar from the Gantt timeline.
     *
     * The client identifies WHICH segment was dragged via ($oldStart, $oldEnd)
     * — those days are removed from `options.dates` and replaced with the new
     * range [$startDate..$endDate]. Other segments are preserved.
     */
    public function updateTaskDates(int $taskId, string $startDate, string $endDate, ?string $oldStart = null, ?string $oldEnd = null): void
    {
        $task = Task::find($taskId);
        if (! $task) {
            \Log::warning('[gantt] updateTaskDates: task not found', ['taskId' => $taskId]);
            return;
        }

        try {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();
            $segOldStart = $oldStart ? Carbon::parse($oldStart)->startOfDay() : $start;
            $segOldEnd   = $oldEnd   ? Carbon::parse($oldEnd)->startOfDay()   : $end;
        } catch (\Exception $e) {
            \Log::warning('[gantt] updateTaskDates: date parse failed', ['taskId' => $taskId, 'err' => $e->getMessage()]);
            return;
        }

        if ($end->lt($start)) {
            \Log::warning('[gantt] updateTaskDates: end < start', ['taskId' => $taskId, 'start' => $startDate, 'end' => $endDate]);
            return;
        }

        // Compute the merged dates list FIRST so overlap check operates on the
        // final state rather than just the dragged segment.
        $newOptions = $this->replaceTaskDateSegment($task, $segOldStart, $segOldEnd, $start, $end);
        $allDates   = $newOptions['dates'] ?? [];
        if (empty($allDates)) {
            \Log::warning('[gantt] updateTaskDates: empty dates after merge', [
                'taskId' => $taskId, 'oldStart' => $oldStart, 'oldEnd' => $oldEnd,
                'newStart' => $startDate, 'newEnd' => $endDate,
                'existingOptions' => $task->options,
            ]);
            return;
        }
        $finalStart = Carbon::parse($allDates[0])->startOfDay();
        $finalEnd   = Carbon::parse($allDates[count($allDates) - 1])->startOfDay();

        \Log::info('[gantt] updateTaskDates', [
            'taskId' => $taskId,
            'input' => compact('startDate', 'endDate', 'oldStart', 'oldEnd'),
            'before' => ['start' => (string) $task->start_date, 'end' => (string) $task->end_date, 'dates' => $task->options->dates ?? null],
            'after'  => ['start' => $finalStart->format('Y-m-d'), 'end' => $finalEnd->format('Y-m-d'), 'dates' => $allDates],
        ]);

        if ($task->wouldOverlapWithSiblings($finalStart, $finalEnd)) {
            \Log::warning('[gantt] updateTaskDates: overlap blocked', ['taskId' => $taskId]);
            Flux::toast(
                duration: 4000,
                position: 'top right',
                variant: 'danger',
                heading: 'Cannot move task',
                text: 'This would overlap with a sibling task.',
            );
            return;
        }

        $task->update([
            'start_date' => $finalStart->format('Y-m-d'),
            'end_date'   => $finalEnd->format('Y-m-d'),
            'options'    => $newOptions,
        ]);

        // SNAPPY DRAG: the gantt bar is already positioned optimistically by Alpine
        // (see _gantt.blade.php startResize/startDrag handlers). Skipping the
        // re-render avoids a 200-500ms morph cycle (ganttRows + dependency arrows
        // + critical path recompute) on every drag/resize. Dependency arrows
        // become slightly stale until the next real interaction; that's an
        // acceptable tradeoff for the responsiveness gain.
        // Invalidate persisted critical-path cache so the next real render
        // recomputes (date changes can re-route the critical path).
        unset($this->criticalPathTaskIds);
        $this->skipRender();

        Flux::toast(
            duration: 2000,
            position: 'top right',
            variant: 'success',
            heading: 'Task updated',
            text: '',
        );
    }

    public function editTask(int $taskId, ?string $day = null, ?int $projectId = null): void
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    /**
     * Create a dependency link by drag-and-drop on the gantt view.
     * Source/target edges determine the dependency type:
     *   finish → start  = finish_to_start
     *   finish → finish = finish_to_finish
     *   start  → start  = start_to_start
     *   start  → finish = start_to_finish
     */
    public function createDependencyLink(int $predecessorId, string $sourceEdge, int $successorId, string $targetEdge): void
    {
        if ($predecessorId === $successorId) {
            return;
        }
        if (! in_array($sourceEdge, ['start', 'finish'], true) || ! in_array($targetEdge, ['start', 'finish'], true)) {
            return;
        }

        $type = $sourceEdge . '_to_' . $targetEdge;

        if (! in_array($type, ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'], true)) {
            return;
        }

        if (TaskDependency::wouldCreateCircularDependency($predecessorId, $successorId)) {
            Flux::toast(
                duration: 3500,
                position: 'top right',
                variant: 'danger',
                heading: 'Cannot link',
                text: 'This would create a circular dependency.',
            );
            return;
        }

        $exists = TaskDependency::where('predecessor_task_id', $predecessorId)
            ->where('successor_task_id', $successorId)
            ->exists();

        if ($exists) {
            Flux::toast(
                duration: 2500,
                position: 'top right',
                variant: 'warning',
                heading: 'Already linked',
                text: '',
            );
            return;
        }

        TaskDependency::create([
            'predecessor_task_id' => $predecessorId,
            'successor_task_id'   => $successorId,
            'type'                => $type,
            'lag_days'            => 0,
        ]);

        // Only dependency-derived caches need invalidation; bar geometry
        // (ganttRows) is unchanged when adding a link, so leave it cached.
        unset(
            $this->ganttDependencyLinks,
            $this->ganttArrowPaths,
            $this->criticalPathTaskIds,
        );

        Flux::toast(
            duration: 2000,
            position: 'top right',
            variant: 'success',
            heading: 'Dependency added',
            text: '',
        );
    }

    /**
     * Replace the dates inside [$oldStart, $oldEnd] in $task->options->dates
     * with the inclusive range [$newStart, $newEnd] (respecting saturday/sunday
     * flags), then return the merged options array with a sorted, deduped
     * `dates` list.
     */
    protected function replaceTaskDateSegment(Task $task, Carbon $oldStart, Carbon $oldEnd, Carbon $newStart, Carbon $newEnd): array
    {
        $options = (array) ($task->options ?? []);
        $saturday = (bool) ($options['saturday'] ?? false);
        $sunday   = (bool) ($options['sunday']   ?? false);

        // Seed the existing dates. If empty, fall back to the task's start/end.
        $existing = [];
        $raw = $options['dates'] ?? null;
        if (is_array($raw) || is_object($raw)) {
            foreach ((array) $raw as $d) {
                if (is_string($d) && $d !== '') {
                    $existing[] = substr($d, 0, 10);
                }
            }
        }
        if (empty($existing) && $task->start_date && $task->end_date) {
            $cursor = Carbon::parse($task->start_date)->startOfDay();
            $stop   = Carbon::parse($task->end_date)->startOfDay();
            while ($cursor->lte($stop)) {
                $existing[] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }
        }

        // Drop the dragged segment.
        $oldStartStr = $oldStart->format('Y-m-d');
        $oldEndStr   = $oldEnd->format('Y-m-d');
        $kept = array_values(array_filter(
            $existing,
            fn (string $d) => $d < $oldStartStr || $d > $oldEndStr,
        ));

        // Add the new segment days. We include weekend days unconditionally
        // here because the user explicitly dragged/resized the bar over them —
        // they want the task to visibly span those days regardless of the
        // saturday/sunday auto-extend flags.
        $cursor = $newStart->copy();
        while ($cursor->lte($newEnd)) {
            $kept[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $kept = array_values(array_unique($kept));
        sort($kept);

        $options['dates'] = $kept;
        return $options;
    }

    /**
     * Build a per-project map of undated Task models, keyed by project id,
     * for the "Pending tasks" modal. The view renders these server-side
     * once so the modal can be opened instantly from the client.
     *
     * @return array<int, array{title: string, tasks: \Illuminate\Support\Collection<int, \App\Models\Task>}>
     */
    protected function buildUndatedTasksByProject(): array
    {
        $map = [];

        foreach ($this->activeProjects as $project) {
            $tasks = $project->tasks->filter(function ($task) {
                $selectedDates = $task->options->dates ?? [];

                return empty($selectedDates) && !$task->start_date && !$task->end_date;
            })->values();

            if ($tasks->isEmpty()) {
                continue;
            }

            $map[$project->id] = [
                'title' => (string) $project->short_address,
                'tasks' => $tasks,
            ];
        }

        return $map;
    }

    public function render()
    {
        $isCards = $this->viewMode === 'cards';
        $isTable = $this->viewMode === 'table';
        $isGantt = $this->viewMode === 'gantt';

        // Each view renders a disjoint dataset. Only compute what the active view
        // needs so changing a filter doesn't pay for views that aren't on screen.
        // The list view in particular only renders the flat `taskList`, so it can
        // skip the heavy kanban / lane / undated computations entirely.
        $needsKanban = $isCards;                 // cards view: per-day project columns
        $needsRows = $isTable;                   // table view: day headers + lane rows
        // Pending-tasks modal: every view whose project sidebar shows the
        // "N pending" chip — the gantt shares that sidebar, and without the
        // modal data its chip silently did nothing.
        $needsUndated = $isCards || $isTable || $isGantt;

        return view('livewire.planner.cards', [
            'kanbanColumns' => $needsKanban ? $this->kanbanColumns : collect(),
            'dayHeaders'    => $needsRows ? $this->dayHeaders : collect(),
            'projectRows'   => $needsRows ? $this->projectRows : collect(),
            'ganttRows'        => $isGantt ? $this->ganttRows : collect(),
            'ganttLinks'       => $isGantt ? $this->ganttDependencyLinks : [],
            'ganttArrowPaths'  => $isGantt ? $this->ganttArrowPaths : [],
            'pxPerDay'         => $this->ganttPxPerDay,
            'undatedTasksByProject' => $needsUndated ? $this->buildUndatedTasksByProject() : [],
        ])->layout('components.layouts.app', [
            'title' => 'Planner',
            'fullscreenClasses' => 'h-full overflow-hidden flex flex-col',
        ]);
    }
}
