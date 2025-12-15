<?php

namespace App\Livewire\Planner;

use App\Models\Task;
use App\Models\Project;

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
    #[Url]
    public $week = '';
    
    public $startDate = null;
    public $totalDays = 28; // 2 weeks before + 2 weeks after = 28 days
    public $daysBeforeToday = 14;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function today(): Carbon
    {
        return browser_today();
    }

    #[Computed]
    public function days()
    {
        // Determine the center date for the window
        $centerDate = $this->startDate
            ? Carbon::parse($this->startDate)
            : ($this->week ? Carbon::parse($this->week)->startOfWeek() : $this->today);

        // Start 2 weeks before the center date
        $startDate = $centerDate->copy()->subDays($this->daysBeforeToday);
        $endDate = $startDate->copy()->addDays($this->totalDays - 1);

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    #[Computed]
    public function projects()
    {
        $startDate = $this->days->first();
        $endDate = $this->days->last();

        $projects = Project::query()
        ->with(['tasks' => function ($query) use ($startDate, $endDate) {
            $query->where('start_date', '<=', $endDate->format('Y-m-d'))
                ->where('end_date', '>=', $startDate->format('Y-m-d'))
                ->orderBy('start_date');
        }, 'latestStatus'])
        ->where(function ($query) use ($startDate, $endDate) {
            $query->whereHas('latestStatus', function ($q) {
                $q->whereIn('status_code', [6, 8]); // Active, Service Call
            })
                  ->orWhereHas('tasks', function ($taskQuery) use ($startDate, $endDate) {
                      $taskQuery->where('start_date', '<=', $endDate->format('Y-m-d'))
                               ->where('end_date', '>=', $startDate->format('Y-m-d'));
                  });
        })
        ->get();

        // Sort in-memory using eager-loaded latestStatus
        $statusPriority = ['Service Call' => 1, 'Active' => 2];
        return $projects->sortBy(function ($project) use ($statusPriority) {
            $latest = $project->latestStatus;
            $title = $latest->title ?? null;
            $priority = $statusPriority[$title] ?? 3;
            $date = $latest?->start_date ?? Carbon::create(9999, 12, 31);
            return [$priority, $date];
        })->values();
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

            $allRenderedTasks = $projectTasks
                ->map(function ($task) {
                    return $this->calculateTaskData($task);
                })
                ->filter()
                // Ensure no duplicate tasks render (defensive)
                ->unique(fn ($taskData) => $taskData['task']->id)
                ->values();

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

            // Row count is used for coordinate math; UI height is handled by CSS (min-h, padding, margins).
            $rowCount = count($taskRows);

            // Calculate last task date if no tasks are visible in timeline
            $lastTaskInfo = null;
            if ($allRenderedTasks->isEmpty()) {
                $lastTask = Task::where('project_id', $project->id)
                    ->whereNotNull('start_date')
                    ->whereNotNull('end_date')
                    ->orderBy('end_date', 'desc')
                    ->first();
                
                if ($lastTask) {
                    $lastTaskEndDate = Carbon::parse($lastTask->end_date);
                    $today = $this->today;
                    $daysAgo = abs($today->diffInDays($lastTaskEndDate));
                    
                    $lastTaskInfo = [
                        'days_ago' => $daysAgo,
                        'end_date' => $lastTaskEndDate,
                        'was_future' => $lastTaskEndDate->isFuture()
                    ];
                }
            }

            return [
                'project' => $project,
                'renderedTasks' => $allRenderedTasks,
                'taskRows' => $taskRows,
                'unscheduledTasks' => $unscheduledTasks,
                'lastTaskInfo' => $lastTaskInfo,
            ];
        });
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function openAddTask(int $projectId): void
    {
        // Trigger a Livewire request here so Flux button can show its loading state
        $this->dispatch('addTask', project_id: $projectId)->to('tasks.task-create');
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

    // Infinite scrolling removed. Manual range adjustment can be added later if needed.

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
            'barWidth' => ($renderEndDayIndex - $renderStartDayIndex + 1) * 100,
        ];
    }

    public function getTaskWeekendExclusions($task, $taskStartDate, $taskEndDate, $barWidth, $renderStartIndex, $renderEndIndex)
    {
        // Clamp indices to current view window
        $renderStartIndex = max(0, (int) $renderStartIndex);
        $renderEndIndex = min(count($this->days) - 1, (int) $renderEndIndex);
        if ($renderEndIndex < $renderStartIndex) {
            return [];
        }

        $visibleCount = ($renderEndIndex - $renderStartIndex + 1);
        if ($visibleCount <= 0) {
            return [];
        }

        $segmentWidth = ($barWidth - 4) / $visibleCount;

        $result = [];
        for ($i = $renderStartIndex; $i <= $renderEndIndex; $i++) {
            $day = $this->days[$i];

            // Only shade if this day is actually within the task duration (should be by definition)
            $isExcludedWeekend = false;
            if ($day->between($taskStartDate, $taskEndDate) && $day->isWeekend()) {
                $isSaturday = $day->isSaturday();
                $isSunday = $day->isSunday();
                if ($isSaturday && !($task->options->saturday ?? false)) {
                    $isExcludedWeekend = true;
                }
                if ($isSunday && !($task->options->sunday ?? false)) {
                    $isExcludedWeekend = true;
                }
            }

            $result[] = [
                'isExcludedWeekend' => $isExcludedWeekend,
                'segmentWidth' => $segmentWidth,
            ];
        }

        return $result;
    }

    #[Title('Gantt Planner')]
    public function render()
    {
        return view('livewire.planner.gantt', [
            'projectsData' => $this->projectsData,
            'days' => $this->days
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}