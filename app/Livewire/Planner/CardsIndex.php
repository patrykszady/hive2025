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
        $this->projects = Project::status([5, 6, 8])->get(); // 5=Scheduled, 6=Active, 8=Service Call
    }

    /**
     * Get 14 days starting from today
     */
    #[Computed]
    public function days()
    {
        $startDate = now('America/Chicago')->startOfDay();
        $endDate = $startDate->copy()->addDays(13); // 14 days total (today + 13)

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    /**
     * Get active projects with their tasks
     */
    #[Computed]
    public function activeProjects()
    {
        return Project::status([6]) // 6=Active
            ->with(['tasks' => function ($query) {
                $query->whereNotNull('start_date')
                    ->whereNotNull('end_date')
                    ->with(['vendor'])
                    ->orderBy('start_date');
            }, 'client.users', 'latestStatus'])
            ->orderBy('address')
            ->get();
    }

    /**
     * Get kanban data - days with all active projects showing tasks for each day
     */
    #[Computed]
    public function kanbanColumns()
    {
        return $this->days->map(function ($day) {
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
                'isToday' => $day->isToday(),
                'isWeekend' => $day->isWeekend(),
                'columns' => $projectColumns,
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
            'fullscreenClasses' => '!p-0',
        ]);
    }
}
