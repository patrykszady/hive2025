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

    // Dynamic properties based on type
    // 'employee', 'project', 'vendor'
    public $type = 'employee';
    public $employee;
    public $project;
    public $vendor;
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount($type = 'employee', $employeeId = null, $projectId = null, $vendorId = null)
    {
        $this->type = $type;

        // $this->vendors = Vendor::all();

        $this->employees = auth()->user()->vendor->users()->employed()->get();
        $this->projects = Project::status(['Active', 'Scheduled', 'Service Call'])->get();

        // Set the specific entity based on type
        switch ($type) {
            case 'employee':
                $this->employee = $employeeId ? User::find($employeeId) : auth()->user();
                break;
            case 'project':
                $this->project = $projectId ? Project::find($projectId) : null;
                break;
            case 'vendor':
                $this->vendor = $vendorId ? Vendor::find($vendorId) : null;
                break;
        }
    }

    #[Computed]
    public function days()
    {
        $startDate = $this->week
            ? Carbon::parse($this->week, config('app.timezone'))->startOfWeek()
            : now()->startOfWeek();

        $endDate = $startDate->copy()->addDays(20);

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    #[Computed]
    public function tasksData()
    {
        $startDate = $this->days->first();
        $endDate = $this->days->last();

        // Get tasks based on type
        $allTasks = $this->getTasksQuery($startDate, $endDate);

        // Get tasks with no dates (separately)
        $noDateTasks = $this->getNoDateTasksQuery();

        // Map tasks to each day
        $dayTasksData = $this->days->map(function ($day) use ($allTasks) {
            $dayFormat = $day->format('Y-m-d');

            $dayTasks = $allTasks->filter(function ($task) use ($dayFormat, $day) {
                // Make sure we're comparing dates properly
                $taskStart = Carbon::parse($task->start_date)->format('Y-m-d');
                $taskEnd = Carbon::parse($task->end_date)->format('Y-m-d');

                // Task should show if the day falls between start and end (inclusive)
                $taskSpansDay = $dayFormat >= $taskStart && $dayFormat <= $taskEnd;

                if (!$taskSpansDay) {
                    return false;
                }

                // Additional check: if this is a weekend day, check if it's included in task options
                if ($day->isWeekend()) {
                    $taskOptions = $task->options ?? (object)[];

                    if ($day->isSaturday() && !($taskOptions->saturday ?? false)) {
                        return false; // Don't show task on Saturday if not enabled
                    }

                    if ($day->isSunday() && !($taskOptions->sunday ?? false)) {
                        return false; // Don't show task on Sunday if not enabled
                    }
                }

                return true;
            })->map(function ($task) use ($day) {
                // Get task family information using existing relationships
                $familyInfo = $this->getTaskFamilyInfo($task, $day);

                return [
                    'task' => $task,
                    'taskTypeColor' => $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue'),
                    'currentFamilyDay' => $familyInfo['currentFamilyDay'],
                    'totalFamilyDays' => $familyInfo['totalFamilyDays'],
                ];
            });

            return [
                'day' => $day,
                'tasks' => $dayTasks,
            ];
        });

        // Add no-date tasks at the beginning
        return collect([
            'noDateTasks' => $noDateTasks->map(function ($task) {
                $familyInfo = $this->getTaskFamilyInfo($task, null);

                return [
                    'task' => $task,
                    'taskTypeColor' => $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue'),
                    'currentFamilyDay' => null,
                    'totalFamilyDays' => $familyInfo['totalFamilyDays'],
                ];
            }),
            'dayTasks' => $dayTasksData
        ]);
    }

    private function getTaskFamilyInfo($task, $currentDay = null)
    {
        $familyTasks = collect();

        // Use the existing relationships to get family tasks
        if ($task->parent_task_id) {
            // This is a child task - get parent + all siblings (including itself)

            // Add the parent
            $parent = Task::find($task->parent_task_id);
            if ($parent) {
                $familyTasks->push($parent);
            }

            // Add all children of the parent (which includes this task and its siblings)
            $allChildren = Task::where('parent_task_id', $task->parent_task_id)->get();
            $familyTasks = $familyTasks->merge($allChildren);

        } else {
            // This is a parent task - get itself + all children
            $familyTasks->push($task);
            $children = $task->children()->get();
            $familyTasks = $familyTasks->merge($children);
        }

        // Get all working days across all family tasks in chronological order
        $allFamilyDays = collect();

        foreach ($familyTasks as $familyTask) {
            if ($familyTask->start_date && $familyTask->end_date) {
                $taskStartDate = Carbon::parse($familyTask->start_date);
                $taskEndDate = Carbon::parse($familyTask->end_date);

                $period = CarbonPeriod::create($taskStartDate, $taskEndDate);

                foreach ($period as $date) {
                    $isSaturday = $date->isSaturday();
                    $isSunday = $date->isSunday();
                    $taskOptions = $familyTask->options ?? (object)[];

                    // Include the day if it's a valid work day for this task
                    $includeDay = (!$isSaturday && !$isSunday) ||
                        ($isSaturday && ($taskOptions->saturday ?? false)) ||
                        ($isSunday && ($taskOptions->sunday ?? false));

                    if ($includeDay) {
                        $allFamilyDays->push($date->format('Y-m-d'));
                    }
                }
            }
        }

        // Remove duplicates and sort chronologically
        $allFamilyDays = $allFamilyDays->unique()->sort()->values();

        $totalFamilyDays = $allFamilyDays->count();
        $currentFamilyDay = null;

        // Find the current day's position if provided
        if ($currentDay) {
            $currentDayFormat = $currentDay->format('Y-m-d');
            $currentFamilyDay = $allFamilyDays->search($currentDayFormat);
            if ($currentFamilyDay !== false) {
                $currentFamilyDay += 1; // Convert to 1-based index
            }
        }

        return [
            'currentFamilyDay' => $currentFamilyDay,
            'totalFamilyDays' => $totalFamilyDays
        ];
    }

    private function getTasksQuery($startDate, $endDate)
    {
        $query = Task::where('start_date', '<=', $endDate->format('Y-m-d'))
            ->where('end_date', '>=', $startDate->format('Y-m-d'))
            ->orderBy('start_date');

        switch ($this->type) {
            case 'employee':
                if (!$this->employee) return collect();
                $query->where(function ($q) {
                    $q->whereJsonContains('user_ids', $this->employee->id)
                      ->orWhereJsonContains('user_ids', (string)$this->employee->id);
                });
                break;

            case 'project':
                if (!$this->project) return collect();
                $query->where('project_id', $this->project->id);
                break;

            case 'vendor':
                if (!$this->vendor) return collect();
                $query->where('vendor_id', $this->vendor->id);
                break;
        }

        return $query->get();
    }

    public function addTaskButton()
    {
        switch ($this->type) {
            case 'project':
                $this->dispatch('addTask',
                    $this->project ? $this->project->id : null  // First parameter: project_id
                )->to('tasks.task-create');
                break;

            case 'vendor':
                $this->dispatch('addTask',
                    null,  // First parameter: project_id (null)
                    null,  // Second parameter: date (null)
                    $this->vendor ? $this->vendor->id : null  // Third parameter: vendor_id
                )->to('tasks.task-create');
                break;

            case 'employee':
                $this->dispatch('addTask',
                    null,  // First parameter: project_id (null)
                    null,  // Second parameter: date (null)
                    null,  // Third parameter: vendor_id (null)
                    $this->employee ? [$this->employee->id] : []  // Fourth parameter: user_ids
                )->to('tasks.task-create');
                break;
        }
    }

    private function getNoDateTasksQuery()
    {
        $query = Task::where(function ($q) {
            $q->whereNull('start_date')
              ->orWhereNull('end_date');
        })->orderBy('created_at');

        switch ($this->type) {
            case 'employee':
                if (!$this->employee) return collect();
                $query->where(function ($q) {
                    $q->whereJsonContains('user_ids', $this->employee->id)
                      ->orWhereJsonContains('user_ids', (string)$this->employee->id);
                });
                break;

            case 'project':
                if (!$this->project) return collect();
                $query->where('project_id', $this->project->id);
                break;

            case 'vendor':
                if (!$this->vendor) return collect();
                $query->where('vendor_id', $this->vendor->id);
                break;
        }

        return $query->get();
    }

    #[Computed]
    public function headerTitle()
    {
        switch ($this->type) {
            case 'employee':
                return $this->employee ? $this->employee->first_name . "'s Tasks" : 'Employee Tasks';
            case 'project':
                return $this->project ? $this->project->address . ' Tasks' : 'Project Tasks';
            case 'vendor':
                return $this->vendor ? $this->vendor->name . ' Tasks' : 'Vendor Tasks';
            default:
                return 'Tasks';
        }
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function render()
    {
        return view('livewire.planner.cards', [
            'tasksData' => $this->tasksData,
            'days' => $this->days,
            'headerTitle' => $this->headerTitle,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
