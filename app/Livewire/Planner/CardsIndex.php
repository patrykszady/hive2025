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

#[Lazy]
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

        // Load base data
        $this->vendors = Vendor::whereNot('business_type', 'Retail')
            ->orderBy('business_name')
            ->get();
        $this->employees = auth()->user()->vendor->users()->employed()->get();
        $this->projects = Project::status(['Active'])->get();

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

        // Map tasks to each day
        return $this->days->map(function ($day) use ($allTasks) {
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
                // Add calculated data to each task
                $taskStartDate = Carbon::parse($task->start_date);
                $taskEndDate = Carbon::parse($task->end_date);

                // Calculate total days accounting for weekend exclusions
                $period = CarbonPeriod::create($taskStartDate, $taskEndDate);
                $totalDays = 0;
                $currentDayNumber = 0;
                $dayFound = false;

                foreach ($period as $date) {
                    $isSaturday = $date->isSaturday();
                    $isSunday = $date->isSunday();
                    $taskOptions = $task->options ?? (object)[];

                    // Include the day if:
                    // - It's a weekday (not Saturday or Sunday)
                    // - It's Saturday and Saturday is enabled
                    // - It's Sunday and Sunday is enabled
                    $includeDay = (!$isSaturday && !$isSunday) ||
                        ($isSaturday && ($taskOptions->saturday ?? false)) ||
                        ($isSunday && ($taskOptions->sunday ?? false));

                    if ($includeDay) {
                        $totalDays++;

                        // Calculate current day number
                        if (!$dayFound && $date->format('Y-m-d') <= $day->format('Y-m-d')) {
                            $currentDayNumber = $totalDays;
                        }

                        if ($date->isSameDay($day)) {
                            $dayFound = true;
                            $currentDayNumber = $totalDays;
                        }
                    }
                }

                return [
                    'task' => $task,
                    'taskTypeColor' => $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue'),
                    'totalDays' => $totalDays,
                    'currentDayNumber' => $currentDayNumber
                ];
            });

            return [
                'day' => $day,
                'tasks' => $dayTasks,
            ];
        });
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

    #[Computed]
    public function headerTitle()
    {
        switch ($this->type) {
            case 'employee':
                return $this->employee ? $this->employee->first_name . "'s Tasks" : 'Employee Tasks';
            case 'project':
                return $this->project ? $this->project->address . ' Tasks' : 'Project Tasks';
            case 'vendor':
                return $this->vendor ? $this->vendor->business_name . ' Tasks' : 'Vendor Tasks';
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
