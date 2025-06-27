<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\User;
use App\Models\Task;
use App\Models\Project;
use Flux;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url; // Add this import

class GanttIndex extends Component
{
    public $vendors = [];
    public $employees = [];

    #[Url] // Add this attribute to make it a query parameter
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->vendors = Vendor::topExpenseVendors()->get();
        $this->employees = auth()->user()->vendor->users()->employed()->get();
    }

    #[Computed]
    public function days()
    {
        $startDate = $this->week ? Carbon::parse($this->week)->startOfWeek() : Carbon::now()->startOfWeek()->subWeek();
        $endDate = $startDate->copy()->addDays(20);

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    #[Computed]
    public function projects()
    {
        $startDate = $this->days->first();
        $endDate = $this->days->last();

        return Project::with(['tasks' => function ($query) use ($startDate, $endDate) {
            $query->where('start_date', '<=', $endDate->format('Y-m-d'))
                ->where('end_date', '>=', $startDate->format('Y-m-d'))
                ->orderBy('start_date');
        }])
        ->where(function ($query) use ($startDate, $endDate) {
            // Get projects that either:
            // 1. Have "Active" status, OR
            // 2. Have tasks scheduled during the date range (regardless of current status)
            $query->status(['Active'])
                  ->orWhereHas('tasks', function ($taskQuery) use ($startDate, $endDate) {
                      $taskQuery->where('start_date', '<=', $endDate->format('Y-m-d'))
                               ->where('end_date', '>=', $startDate->format('Y-m-d'));
                  });
        })
        ->get();
    }

    #[Computed]
    public function projectsData()
    {
        return $this->projects->map(function ($project) {
            // Get tasks for this project within the date range
            $projectTasks = $project->tasks;

            // Get tasks without start_date or end_date for this project
            $unscheduledTasks = Task::where('project_id', $project->id)
                ->where(function ($query) {
                    $query->whereNull('start_date')
                        ->orWhereNull('end_date');
                })
                ->get();

            // Generate rendered tasks data
            $allRenderedTasks = $projectTasks->map(function ($task) {
                return $this->calculateTaskData($task);
            })->filter()->values();

            // Group tasks by family (parent_task_id or own id if parent/standalone)
            $groupedTasks = $allRenderedTasks->groupBy(function ($taskData) {
                $task = $taskData['task'];
                // Group by parent_id, or use own ID if it's a parent/standalone
                return $task->parent_task_id ?? $task->id;
            });

            // Flatten grouped tasks into rows - family members go on same row
            $taskRows = [];
            foreach ($groupedTasks as $familyId => $familyTasks) {
                // Sort siblings by creation date within each family
                $sortedFamily = $familyTasks->sortBy(function ($taskData) {
                    return $taskData['task']->created_at;
                })->values()->toArray();

                $taskRows[] = $sortedFamily;
            }

            // Calculate the height needed for this project (based on rows, not individual tasks)
            $rowCount = count($taskRows);
            $taskBarHeight = 60;
            $taskBarMarginY = 4;

            $projectTimelineHeight = ($rowCount * ($taskBarHeight + ($taskBarMarginY * 2))) + $taskBarMarginY;
            $projectTimelineHeight = max($projectTimelineHeight, 80);

            return [
                'project' => $project,
                'renderedTasks' => $allRenderedTasks, // Keep for compatibility
                'taskRows' => $taskRows, // New grouped structure
                'unscheduledTasks' => $unscheduledTasks,
                'projectTimelineHeight' => $projectTimelineHeight
            ];
        });
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function updateTaskDates($taskId, $startIndex, $endIndex)
    {
        $task = Task::find($taskId);
        if (!$task) return;

        // Calculate new dates
        if ($startIndex < 0) {
            $startDate = $this->days->first()->copy()->addDays($startIndex);
        } else {
            $startDate = $this->days[$startIndex];
        }

        if ($endIndex >= count($this->days)) {
            $endDate = $this->days->last()->copy()->addDays($endIndex - (count($this->days) - 1));
        } else {
            $endDate = $this->days[$endIndex];
        }

        // Check for sibling overlaps
        if ($task->wouldOverlapWithSiblings($startDate, $endDate)) {
            Flux::toast(
                duration: 4000,
                position: 'top right',
                variant: 'danger',
                heading: 'Cannot Update Task',
                text: 'This would overlap with a sibling task.',
            );
            return;
        }

        // Update the task
        $task->update([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d')
        ]);

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Updated',
            text: '',
        );
    }

    private function calculateTaskData($task)
    {
        if (!$task->start_date || !$task->end_date) {
            return null;
        }

        $taskStartDate = Carbon::parse($task->start_date);
        $taskEndDate = Carbon::parse($task->end_date);
        $renderStartDate = $taskStartDate->isBefore($this->days->first()) ? $this->days->first() : $taskStartDate;
        $renderEndDate = $taskEndDate->isAfter($this->days->last()) ? $this->days->last() : $taskEndDate;
        $renderStartDayIndex = $this->days->search(fn($d) => $d->isSameDay($renderStartDate));
        $renderEndDayIndex = $this->days->search(fn($d) => $d->isSameDay($renderEndDate));

        if ($renderStartDayIndex === false || $renderEndDayIndex === false || $renderStartDayIndex > $renderEndDayIndex) {
            return null;
        }

        return [
            'task' => $task,
            'taskStartDate' => $taskStartDate,
            'taskEndDate' => $taskEndDate,
            'renderStartDayIndex' => $renderStartDayIndex,
            'renderEndDayIndex' => $renderEndDayIndex,
            'leftPosition' => $renderStartDayIndex * 100, // dayColumnWidth
            'taskDurationInDays' => $renderEndDayIndex - $renderStartDayIndex + 1,
            'barWidth' => ($renderEndDayIndex - $renderStartDayIndex + 1) * 100, // dayColumnWidth
        ];
    }

    public function getTaskWeekendExclusions($task, $taskStartDate, $taskEndDate, $barWidth)
    {
        // Create a period for the entire task duration
        $taskPeriod = CarbonPeriod::create($taskStartDate, $taskEndDate);
        $taskDays = iterator_to_array($taskPeriod);

        // Only show overlay for the visible portion of the task
        $visibleTaskDays = [];
        foreach($taskDays as $taskDay) {
            // Check if this task day falls within our visible day range
            if ($taskDay->between($this->days->first(), $this->days->last())) {
                $isTaskDayWeekend = $taskDay->isWeekend();
                $isTaskDaySaturday = $taskDay->isSaturday();
                $isTaskDaySunday = $taskDay->isSunday();

                // Check if this weekend day is excluded from task options
                $isExcludedWeekend = false;
                if ($isTaskDayWeekend) {
                    if ($isTaskDaySaturday && !($task->options->saturday ?? false)) {
                        $isExcludedWeekend = true;
                    }
                    if ($isTaskDaySunday && !($task->options->sunday ?? false)) {
                        $isExcludedWeekend = true;
                    }
                }

                $visibleTaskDays[] = [
                    'isExcludedWeekend' => $isExcludedWeekend,
                    'segmentWidth' => ($barWidth - 4) / count($taskDays)
                ];
            }
        }

        return $visibleTaskDays;
    }

    public function render()
    {
        return view('livewire.planner.gantt', [
            'projectsData' => $this->projectsData,
            'days' => $this->days,
            'employees' => $this->employees,
            'vendors' => $this->vendors,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
