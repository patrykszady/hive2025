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

    private const PLANNER_PROJECT_STATUS_CODES = [4, 5, 6, 8]; // Prep, Scheduled, Active, Service Call
    private const PLANNER_STATUS_PRIORITY = [8 => 1, 6 => 2, 4 => 3, 5 => 4]; // Service Call, Active, Prep, Scheduled

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->employees = auth()->user()->vendor->users()->employed()->get();
        $this->vendors = Vendor::all();
        $this->projects = Project::status(self::PLANNER_PROJECT_STATUS_CODES)
            ->with('latestStatus')
            ->get()
            ->sortBy(function (Project $project): string {
                $priority = self::PLANNER_STATUS_PRIORITY[$project->latestStatus->status_code ?? 0] ?? 999;
                $startDate = ($project->latestStatus?->start_date?->format('Y-m-d')) ?: '9999-12-31';

                return str_pad((string) $priority, 3, '0', STR_PAD_LEFT)
                    .'-'.$startDate
                    .'-'.mb_strtolower((string) ($project->address ?? ''));
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
     * Get active projects with their tasks
     */
    #[Computed]
    public function activeProjects()
    {
        return Project::status(self::PLANNER_PROJECT_STATUS_CODES)
            ->with(['tasks' => function ($query) {
                $query->whereNotNull('start_date')
                    ->whereNotNull('end_date')
                    ->with(['vendor'])
                    ->orderBy('start_date');
            }, 'client.users', 'latestStatus'])
            ->orderBy('address')
            ->get()
            ->sortBy(function (Project $project): string {
                $priority = self::PLANNER_STATUS_PRIORITY[$project->latestStatus->status_code ?? 0] ?? 999;
                $startDate = ($project->latestStatus?->start_date?->format('Y-m-d')) ?: '9999-12-31';

                return str_pad((string) $priority, 3, '0', STR_PAD_LEFT)
                    .'-'.$startDate
                    .'-'.mb_strtolower((string) ($project->address ?? ''));
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
                // Filter tasks that fall on this specific day
                $dayTasks = $project->tasks->filter(function ($task) use ($dayFormat, $day) {
                    if (!$task->start_date || !$task->end_date) {
                        return false;
                    }

                    // Check if this specific date is in the selected dates array
                    $selectedDates = $task->options->dates ?? [];
                    
                    if (!empty($selectedDates)) {
                        // New way: check if day is in selected dates
                        return in_array($dayFormat, $selectedDates);
                    } else {
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

                // Return project with tasks (even if no tasks for this day)
                return (object) [
                    'id' => $project->id,
                    'title' => $project->address,
                    'project' => $project,
                    'cards' => $dayTasks,
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

    public function addTask($projectId = null, $date = null)
    {
        $this->dispatch('addTask', $projectId, $date)->to('tasks.task-create');
    }

    public function editTask(int $taskId, ?string $day = null, ?int $projectId = null): void
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
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
