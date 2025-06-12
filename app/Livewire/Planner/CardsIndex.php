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
    public $employee;
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->vendors = Vendor::whereNot('business_type', 'Retail')
            ->orderBy('business_name')
            ->get();

        $this->employees = auth()->user()->vendor->users()->employed()->get();
        $this->employee = auth()->user();
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
    public function projects()
    {
        return Project::status(['Active'])->get();
    }

    #[Computed]
    public function tasksData()
    {
        if (!$this->employee) return collect();

        $startDate = $this->days->first();
        $endDate = $this->days->last();

        // Get all tasks where this employee is assigned within the date range
        $allTasks = Task::where('start_date', '<=', $endDate->format('Y-m-d'))
            ->where('end_date', '>=', $startDate->format('Y-m-d'))
            ->where(function ($query) {
                $query->whereJsonContains('user_ids', $this->employee->id)
                      ->orWhereJsonContains('user_ids', (string)$this->employee->id);
            })
            // ->with(['project', 'vendor', 'users'])
            ->orderBy('start_date')
            ->get();

        // Map tasks to each day - a task should appear on EVERY day it spans
        return $this->days->map(function ($day) use ($allTasks) {
            $dayFormat = $day->format('Y-m-d');

            $dayTasks = $allTasks->filter(function ($task) use ($dayFormat) {
                // Make sure we're comparing dates properly
                $taskStart = Carbon::parse($task->start_date)->format('Y-m-d');
                $taskEnd = Carbon::parse($task->end_date)->format('Y-m-d');

                // Task should show if the day falls between start and end (inclusive)
                return $dayFormat >= $taskStart && $dayFormat <= $taskEnd;
            });

            return [
                'day' => $day,
                'tasks' => $dayTasks,
                // Debug info - remove this after testing
                'debug' => [
                    'dayFormat' => $dayFormat,
                    'taskCount' => $dayTasks->count(),
                ]
            ];
        });
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
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
