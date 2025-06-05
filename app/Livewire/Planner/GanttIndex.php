<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\Task;
use App\Models\Project;
use Flux;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;
use Livewire\Attributes\Computed;

class GanttIndex extends Component
{
    public $vendors = [];
    public $employees = [];
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        // 12-9-2024 also used in VendorIndex and GanttIndex.. needs to be a global scope
        $this->vendors = Vendor::whereNot('business_type', 'Retail')
            ->withCount([
                'expenses',
                'expenses as expense_count' => function ($query) {
                    $query->where('created_at', '>=', today()->subYear());
                },
            ])
            //as expense count
            // sort by expenses ytd
            ->tap(fn ($query) => 'expense_count' ? $query->orderBy('expense_count', 'desc') : $query)
            ->get();

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
        }])->status(['Active'])->get();
    }

    #[Computed]
    public function projectsData()
    {
        return $this->projects->map(function ($project) {
            // Get tasks for this project within the date range
            $projectTasks = $project->tasks;

            // Generate rendered tasks data using your existing method
            $renderedTasks = $projectTasks->map(function ($task) {
                return $this->calculateTaskData($task);
            })->filter()->values(); // Remove null values and reindex

            // Calculate the height needed for all tasks in this project
            $taskCount = $renderedTasks->count(); // Use rendered tasks count
            $taskBarHeight = 60; // Must match the Blade template
            $taskBarMarginY = 4; // Must match the Blade template

            $projectTimelineHeight = ($taskCount * ($taskBarHeight + ($taskBarMarginY * 2))) + $taskBarMarginY;

            // Minimum height to prevent empty projects from being too small
            $projectTimelineHeight = max($projectTimelineHeight, 80);

            return [
                'project' => $project,
                'renderedTasks' => $renderedTasks,
                'projectTimelineHeight' => $projectTimelineHeight
            ];
        });
    }

    public function updateTaskDates($taskId, $startIndex, $endIndex)
    {
        $task = Task::find($taskId);
        if ($task) {
            // Handle negative start index (task starts before view)
            if ($startIndex < 0) {
                $startDate = $this->days->first()->copy()->addDays($startIndex);
            } else {
                $startDate = $this->days[$startIndex];
            }

            // Handle end index beyond view (task ends after view)
            if ($endIndex >= count($this->days)) {
                $endDate = $this->days->last()->copy()->addDays($endIndex - (count($this->days) - 1));
            } else {
                $endDate = $this->days[$endIndex];
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

    public function render()
    {
        return view('livewire.planner.gantt', [
            'projectsData' => $this->projectsData,
            'days' => $this->days,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
