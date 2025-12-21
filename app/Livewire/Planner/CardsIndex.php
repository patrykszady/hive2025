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

    public function toggleMobileFilters(): void
    {
        $this->showMobileFilters = ! $this->showMobileFilters;
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
     * Get 14 days starting from today
     */
    #[Computed]
    public function days()
    {
        $startDate = browser_today();
        $endDate = $startDate->copy()->addDays(13); // 14 days total (today + 13)

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
        $query = Project::status(self::PLANNER_PROJECT_STATUS_CODES)
            ->with(['tasks' => function ($query) {
                $taskQuery = $query
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

        return $this->days->map(function ($day) use ($today, $tomorrow) {
            $dayFormat = $day->format('Y-m-d');

            // Show ALL active projects in each day column
            $projectColumns = $this->activeProjects->map(function ($project) use ($day, $dayFormat) {
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

                // Calculate next/last task info for this project on this day
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

            return (object) [
                'day' => $day,
                'title' => $day->format('D, M j'),
                'isToday' => $day->isSameDay($today),
                'isTomorrow' => $day->isSameDay($tomorrow),
                'isWeekend' => $day->isWeekend(),
                'columns' => $projectColumns,
            ];
        })->values();
    }

    /**
     * Calculate task gap info for a project on a given day
     * Returns info about next upcoming task or last past task (excluding weekends)
     */
    private function calculateTaskGapInfo($project, Carbon $currentDay): ?object
    {
        $currentDayFormat = $currentDay->format('Y-m-d');
        
        // Get all task dates for this project
        $allTaskDates = collect();
        
        foreach ($project->tasks as $task) {
            $selectedDates = $task->options->dates ?? [];
            
            if (!empty($selectedDates)) {
                foreach ($selectedDates as $date) {
                    $allTaskDates->push($date);
                }
            } elseif ($task->start_date) {
                $allTaskDates->push(Carbon::parse($task->start_date)->format('Y-m-d'));
            }
        }
        
        $allTaskDates = $allTaskDates->unique()->sort()->values();
        
        if ($allTaskDates->isEmpty()) {
            return null;
        }
        
        // Find next task date (after current day)
        $nextTaskDate = $allTaskDates->first(fn($date) => $date > $currentDayFormat);
        
        // Find last task date (before current day)
        $lastTaskDate = $allTaskDates->last(fn($date) => $date < $currentDayFormat);
        
        // Calculate weekday difference (excluding weekends)
        $countWeekdays = function (Carbon $from, Carbon $to): int {
            $days = 0;
            $current = $from->copy();
            
            while ($current->lt($to)) {
                $current->addDay();
                if (!$current->isWeekend()) {
                    $days++;
                }
            }
            
            return $days;
        };
        
        $result = null;
        
        // Prefer showing next task if available
        if ($nextTaskDate) {
            $nextDate = Carbon::parse($nextTaskDate);
            $daysUntil = $countWeekdays($currentDay, $nextDate);
            
            if ($daysUntil > 0) {
                $result = (object) [
                    'type' => 'next',
                    'days' => $daysUntil,
                    'label' => $daysUntil === 1 ? 'Next in 1 day' : "Next in {$daysUntil} days",
                ];
            }
        }
        
        // If no next task, show last task info
        if (!$result && $lastTaskDate) {
            $lastDate = Carbon::parse($lastTaskDate);
            $daysAgo = $countWeekdays($lastDate, $currentDay);
            
            if ($daysAgo > 0) {
                $result = (object) [
                    'type' => 'last',
                    'days' => $daysAgo,
                    'label' => $daysAgo === 1 ? 'Last 1 day ago' : "Last {$daysAgo} days ago",
                ];
            }
        }
        
        return $result;
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
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
