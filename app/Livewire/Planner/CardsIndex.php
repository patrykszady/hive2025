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

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->employees = auth()->user()->vendor->users()->employed()->get();
        $this->vendors = Vendor::all();
        $this->projects = Project::status(['Active', 'Scheduled', 'Service Call'])->get();
    }

    /**
     * Get 14 days starting from today
     */
    #[Computed]
    public function days()
    {
        $startDate = now()->startOfDay();
        $endDate = $startDate->copy()->addDays(13); // 14 days total (today + 13)

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    /**
     * Get active projects with their tasks
     */
    #[Computed]
    public function activeProjects()
    {
        return Project::status(['Active'])
            ->with(['tasks' => function ($query) {
                $query->whereNotNull('start_date')
                    ->whereNotNull('end_date')
                    ->orderBy('start_date');
            }])
            ->orderBy('address')
            ->get();
    }

    /**
     * Get kanban columns data - 14 days with projects and their tasks
     */
    #[Computed]
    public function kanbanColumns()
    {
        $startDate = $this->days->first();
        $endDate = $this->days->last();

        return $this->days->map(function ($day) use ($startDate, $endDate) {
            $dayFormat = $day->format('Y-m-d');

            // Get projects that have tasks on this day
            $projectsWithTasks = $this->activeProjects->map(function ($project) use ($day, $dayFormat) {
                // Filter tasks that fall on this specific day
                $dayTasks = $project->tasks->filter(function ($task) use ($dayFormat, $day) {
                    if (!$task->start_date || !$task->end_date) {
                        return false;
                    }

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
                })->values();

                if ($dayTasks->isEmpty()) {
                    return null;
                }

                return [
                    'project' => $project,
                    'tasks' => $dayTasks,
                ];
            })->filter()->values();

            return [
                'day' => $day,
                'title' => $day->format('D, M j'),
                'isToday' => $day->isToday(),
                'isWeekend' => $day->isWeekend(),
                'projectCards' => $projectsWithTasks,
                'taskCount' => $projectsWithTasks->sum(fn($p) => $p['tasks']->count()),
            ];
        });
    }

    public function addTask($projectId = null)
    {
        $this->dispatch('addTask', $projectId)->to('tasks.task-create');
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function render()
    {
        return view('livewire.planner.cards', [
            'kanbanColumns' => $this->kanbanColumns,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
