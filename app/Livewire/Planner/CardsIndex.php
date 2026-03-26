<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\Task;
use App\Models\User;
use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;

// #[Lazy]
class CardsIndex extends Component
{
    public $vendors = [];
    public $employees = [];
    public $projects = [];

    // Filter properties
    public array $filterProjectIds = [];
    public ?int $filterVendorId = null;
    public array $filterUserIds = [];
    public array $filterStatusCodes = [];
    public bool $showMobileFilters = false;

    public int $previousDaysLoaded = 0;
    public int $futureDaysLoaded = 14;

    private const DAYS_PER_LOAD = 2;

    public function toggleMobileFilters(): void
    {
        $this->showMobileFilters = ! $this->showMobileFilters;
    }

    public function loadPreviousDays(): void
    {
        $this->previousDaysLoaded += self::DAYS_PER_LOAD;
        $this->dispatch('planner-scroll-to', direction: 'start');
    }

    public function loadFutureDays(): void
    {
        $this->futureDaysLoaded += self::DAYS_PER_LOAD;
        $this->dispatch('planner-scroll-to', direction: 'end');
    }

    public array $undatedTasksModalTasks = [];
    public ?string $undatedTasksModalProjectTitle = null;
    public ?int $undatedTasksModalProjectId = null;

    private const PLANNER_PROJECT_STATUS_CODES = [4, 5, 6, 8]; // Prep, Scheduled, Active, Service Call
    private const PLANNER_STATUS_PRIORITY = [8 => 1, 6 => 2, 4 => 3, 5 => 4]; // Service Call, Active, Prep, Scheduled

    protected $listeners = ['refreshComponent' => '$refresh'];

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
        return collect([
            ['code' => 4, 'label' => 'Prep', 'color' => 'amber'],
            ['code' => 5, 'label' => 'Scheduled', 'color' => 'lime'],
            ['code' => 6, 'label' => 'Active', 'color' => 'green'],
            ['code' => 8, 'label' => 'Service Call', 'color' => 'orange'],
        ]);
    }

    /**
     * Clear all filters
     */
    public function clearFilters()
    {
        $this->filterProjectIds = [];
        $this->filterVendorId = null;
        $this->filterUserIds = [];
        $this->filterStatusCodes = [];
    }

    /**
     * Check if any filters are active
     */
    #[Computed]
    public function hasActiveFilters()
    {
        return !empty($this->filterProjectIds)
            || $this->filterVendorId !== null
            || !empty($this->filterUserIds)
            || !empty($this->filterStatusCodes);
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

            return (object) [
                'id' => $project->id,
                'title' => $project->short_address,
                'project' => $project,
                'undated_tasks_count' => $undatedTasksCount,
                'hasTasksInRange' => $hasTasksInRange,
                'hasPlannerStatus' => $hasPlannerStatus,
                'dayCells' => $dayCells,
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

    public function editTask(int $taskId, ?string $day = null, ?int $projectId = null): void
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function openUndatedTasksModal(int $projectId): void
    {
        /** @var \App\Models\Project|null $project */
        $project = $this->activeProjects->firstWhere('id', $projectId);

        if (!$project) {
            return;
        }

        $tasks = $project->tasks
            ->filter(function ($task) {
                $selectedDates = $task->options->dates ?? [];

                return empty($selectedDates) && !$task->start_date && !$task->end_date;
            })
            ->values();

        $this->undatedTasksModalProjectId = $project->id;
        $this->undatedTasksModalProjectTitle = $project->short_address;
        $this->undatedTasksModalTasks = $tasks
            ->map(function (Task $task): array {
                $taskUsers = $task->users;

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'type_text_class' => (string) data_get($task->type_ui, 'text', ''),
                    'users' => $taskUsers
                        ->take(3)
                        ->map(fn (\App\Models\User $user): array => [
                            'id' => $user->id,
                            'full_name' => $user->full_name,
                        ])
                        ->values()
                        ->all(),
                    'users_count' => $taskUsers->count(),
                    'vendor' => $task->vendor
                        ? [
                            'id' => $task->vendor->id,
                            'name' => $task->vendor->name,
                        ]
                        : null,
                ];
            })
            ->all();

        $this->modal('planner_undated_tasks_modal')->show();
    }

    public function editUndatedTask(int $taskId): void
    {
        $this->modal('planner_undated_tasks_modal')->close();

        $this->editTask($taskId);
    }

    public function render()
    {
        return view('livewire.planner.cards', [
            'kanbanColumns' => $this->kanbanColumns,
            'dayHeaders' => $this->dayHeaders,
            'projectRows' => $this->projectRows,
        ])->layout('components.layouts.app', [
            'title' => 'Planner',
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
