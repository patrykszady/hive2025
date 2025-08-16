<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\User;
use App\Models\Task;
use App\Models\Project;
use App\Models\TaskDependency;
use App\Traits\CalculatesDependencyPaths;
use Flux;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Schema;

class GanttIndex extends Component
{
    use CalculatesDependencyPaths;

    public $vendors = [];
    public $employees = [];

    #[Url]
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        // Use Scout search to sort by ytd_expense_sum
        $this->vendors = Vendor::search('*')
            ->orderBy('ytd_expense_sum', 'desc')
            ->get();
            
        $this->employees = auth()->user()->vendor->users()->employed()->get();
    }

    #[Computed]
    public function days()
    {
        $startDate = $this->week ? Carbon::parse($this->week)->startOfWeek() : Carbon::now()->startOfWeek()->subWeeks(2);
        $endDate = $startDate->copy()->addDays(30);

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
                ->with(['predecessorDependencies', 'successorDependencies'])
                ->orderBy('start_date');
        }])
        ->where(function ($query) use ($startDate, $endDate) {
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
            $projectTasks = $project->tasks;

            $unscheduledTasks = Task::where('project_id', $project->id)
                ->where(function ($query) {
                    $query->whereNull('start_date')
                        ->orWhereNull('end_date');
                })
                ->get();

            $allRenderedTasks = $projectTasks->map(function ($task) {
                return $this->calculateTaskData($task);
            })->filter()->values();

            $groupedTasks = $allRenderedTasks->groupBy(function ($taskData) {
                $task = $taskData['task'];
                return $task->parent_task_id ?? $task->id;
            });

            $taskRows = [];
            foreach ($groupedTasks as $familyId => $familyTasks) {
                $sortedFamily = $familyTasks->sortBy(function ($taskData) {
                    return $taskData['task']->created_at;
                })->values()->toArray();

                $taskRows[] = $sortedFamily;
            }

            $rowCount = count($taskRows);
            $taskBarHeight = 60;
            $taskBarMarginY = 4;

            $projectTimelineHeight = ($rowCount * ($taskBarHeight + ($taskBarMarginY * 2))) + $taskBarMarginY;
            $projectTimelineHeight = max($projectTimelineHeight, 80);

            return [
                'project' => $project,
                'renderedTasks' => $allRenderedTasks,
                'taskRows' => $taskRows,
                'unscheduledTasks' => $unscheduledTasks,
                'projectTimelineHeight' => $projectTimelineHeight
            ];
        });
    }

    #[Computed]
    public function projectDependencies()
    {
        $projectIds = $this->projects->pluck('id')->toArray();

        return TaskDependency::whereHas('predecessor', function ($query) use ($projectIds) {
            $query->whereIn('project_id', $projectIds);
        })
        ->whereHas('successor', function ($query) use ($projectIds) {
            $query->whereIn('project_id', $projectIds);
        })
        ->with(['predecessor', 'successor'])
        ->get();
    }

    #[Computed]
    public function dependencyLines()
    {
        return $this->calculateDependencyLines();
    }

    private function findTaskCoordinates($taskId)
    {
        foreach ($this->projectsData as $projectIndex => $projectData) {
            foreach ($projectData['taskRows'] as $rowIndex => $taskRow) {
                foreach ($taskRow as $taskData) {
                    if ($taskData['task']->id === $taskId) {
                        $projectHeaderHeight = 76;
                        $taskBarHeight = 60;
                        $taskBarMarginY = 4;
                        
                        $y = 49;
                        for ($i = 0; $i < $projectIndex; $i++) {
                            $y += $projectHeaderHeight + $this->projectsData[$i]['projectTimelineHeight'];
                        }
                        $y += $projectHeaderHeight;
                        
                        // This should match the template exactly
                        $templateTopPosition = ($rowIndex * ($taskBarHeight + $taskBarMarginY * 2)) + $taskBarMarginY;
                        $absoluteTopPosition = $y + $templateTopPosition;
                        
                        $rawLeftPosition = $taskData['leftPosition'];
                        $rawBarWidth = $taskData['barWidth'];
                        $actualLeftPosition = $rawLeftPosition + 2;
                        $actualBarWidth = $rawBarWidth - 4;
                        
                        // Add a small correction factor that increases with depth
                        $verticalCorrection = ($projectIndex * 0.5) + ($rowIndex * 0.25);

                        $fixedOffset = 8; // Increase to 8 pixels below the task bar

                        $coords = [
                            'x' => $actualLeftPosition + $actualBarWidth,
                            'centerX' => $actualLeftPosition + ($actualBarWidth / 2),
                            'y' => $absoluteTopPosition + ($taskBarHeight / 2),
                            'topY' => $absoluteTopPosition,
                            'bottomY' => $absoluteTopPosition + $taskBarHeight + $verticalCorrection + $fixedOffset, // Add the correction
                            'startX' => $actualLeftPosition,
                            'width' => $actualBarWidth,
                            'rowIndex' => $rowIndex,
                            'projectIndex' => $projectIndex,
                        ];

                        return $coords;
                    }
                }
            }
        }

        return null;
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function updateTaskDates($taskId, $startIndex, $endIndex)
    {
        $task = Task::find($taskId);
        if (!$task) return;

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
            'leftPosition' => $renderStartDayIndex * 100,
            'taskDurationInDays' => $renderEndDayIndex - $renderStartDayIndex + 1,
            'barWidth' => ($renderEndDayIndex - $renderStartDayIndex + 1) * 100,
        ];
    }

    public function getTaskWeekendExclusions($task, $taskStartDate, $taskEndDate, $barWidth)
    {
        $taskPeriod = CarbonPeriod::create($taskStartDate, $taskEndDate);
        $taskDays = iterator_to_array($taskPeriod);

        $visibleTaskDays = [];
        foreach($taskDays as $taskDay) {
            if ($taskDay->between($this->days->first(), $this->days->last())) {
                $isTaskDayWeekend = $taskDay->isWeekend();
                $isTaskDaySaturday = $taskDay->isSaturday();
                $isTaskDaySunday = $taskDay->isSunday();

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

    #[Title('Gantt Planner')]
    public function render()
    {
        $taskDependencies = [];
        foreach ($this->projectDependencies as $dependency) {
            $taskDependencies[$dependency->successor_task_id][] = [
                'predecessorId' => $dependency->predecessor_task_id,
                'type' => $dependency->type,
                'isBlocking' => $dependency->isBlocking(),
            ];
        }

        return view('livewire.planner.gantt', [
            'projectsData' => $this->projectsData,
            'days' => $this->days,
            'employees' => $this->employees,
            'vendors' => $this->vendors,
            'dependencyLines' => $this->dependencyLines,
            'taskDependencies' => $taskDependencies,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
