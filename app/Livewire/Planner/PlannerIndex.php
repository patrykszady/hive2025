<?php

namespace App\Livewire\Planner;

use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class PlannerIndex extends Component
{
    public $employees = [];

    public $projects = [];

    public $vendors = [];

    public $days = [];

    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected $queryString = [
        'week' => ['except' => ''],
    ];

    public function mount()
    {
        if ($this->week) {
            //5-24-2024 must be Y-m-d format, else go to else below
            $monday = $this->week;
        } else {
            $monday = today()->format('Y-m-d');
        }

        $this->days = $this->set_week_days($monday);

        $this->projects = Project::with('tasks')
            ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
            ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']]);

        // 12-9-2024 also used in VendorIndex .. needs to be a global scope
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

    public function set_week_days($monday)
    {
        $days = CarbonPeriod::create(
            Carbon::parse($monday)->subWeeks(1)->startOfWeek(Carbon::MONDAY),
            '1 day',
            Carbon::parse($monday)->addWeeks(2)->endOfWeek(Carbon::SUNDAY)
        );

        $week_days = [
            0 => [
                'database_date' => null,
                'formatted_date' => null,
                'is_today' => false,
                'is_weekend' => false,
                'is_saturday' => false,
                'is_sunday' => false,
            ],
        ];

        foreach ($days as $confirmed_date) {
            //need to account for saturday&sunday / days off
            $week_days[] = [
                'database_date' => $confirmed_date->format('Y-m-d'),
                'formatted_date' => $confirmed_date->format('D, m/d'),
                'is_today' => $confirmed_date == today(),
                'is_weekend' => $confirmed_date->isWeekend(),
                'is_saturday' => $confirmed_date->isSaturday(),
                'is_sunday' => $confirmed_date->isSunday(),
            ];
        }

        return $week_days;
    }

    public function sort($key, $position, $project_id, $date_index)
    {
        $project = Project::findOrFail($project_id);
        $task = Task::findOrFail($key);

        if (is_null($this->days[$date_index]['database_date'])) {
            $start_date = null;
        } else {
            $start_date = Carbon::parse($this->days[$date_index]['database_date']);
        }

        // If this Task does not belong to this Project, Move the task to new project.
        if ($task->project->isNot($project)) {
            $task->displace();
            $task->project()->associate($project);
        }

        $task->start_date = $start_date;
        $task_days_count = $task->duration;

        if (in_array($task_days_count, [0, 1])) {
            $task->end_date = $task->start_date;
            $task->duration = $task_days_count;

            $options = $task->options;
            $include_weekends = [];
            if (! is_null($task->start_date)) {
                if ($start_date->isSaturday()) {
                    $include_weekends['saturday'] = true;
                }

                if ($start_date->isSunday()) {
                    $include_weekends['sunday'] = true;
                }
            }

            $options->include_weekend_days = $include_weekends;
            $task->options = $options;

            //2024-12-15 SAME ON PlannerCard
            //if not weekend day/ set true on $task
            // $excludeSaturdays = !isset($include_weekends['saturday']) || $include_weekends['saturday'] === false;
            // $excludeSundays = !isset($include_weekends['sunday']) || $include_weekends['sunday'] === false;

            // $startDate = $start_date;
            // // $daysCount = ($startDate->isSaturday() && $excludeSaturdays === true) || ($startDate->isSunday() && $excludeSundays === true) ? 0 : 1;
            // $daysCount = $excludeSaturdays == true || $startDate->isSunday() == true ? 0 : 1;
            // $endDate = $startDate->copy()->addDays($task_days_count - $daysCount);

            // $duration = $this->countDaysBetweenDates($startDate, $endDate, $excludeSaturdays, $excludeSundays);

            // $task->duration = $duration;
            // $task->start_date = $startDate;
            // $task->end_date = $endDate;
        } else {
            $include_weekends = (array) $task->options->include_weekend_days;
            $excludeSaturdays = ! isset($include_weekends['saturday']) || $include_weekends['saturday'] === false;
            $excludeSundays = ! isset($include_weekends['sunday']) || $include_weekends['sunday'] === false;

            $startDate = $start_date;
            // $daysCount = ($startDate->isSaturday() && $excludeSaturdays === true) || ($startDate->isSunday() && $excludeSundays === true) ? 0 : 1;
            $daysCount = $excludeSaturdays == true || $startDate->isSunday() == true ? 0 : 1;
            $endDate = $startDate->copy()->addDays($task_days_count - $daysCount);

            $duration = $this->countDaysBetweenDates($startDate, $endDate, $excludeSaturdays, $excludeSundays);

            $task->duration = $duration;
            $task->start_date = $startDate;
            $task->end_date = $endDate;
        }

        $task->save();
        $task->move($position);

        Flux::toast(
            duration: 2000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Moved',
            // route / href / wire:click
            text: '',
        );
    }

    //Copilot help
    //2024-12-10 SAME ON PlannerCard
    //count days between dates and ignore weekend days if checkbox true
    public function countDaysBetweenDates($startDate, $endDate, $excludeSaturdays = true, $excludeSundays = true)
    {
        // Include the first day in the count if not saturday or sunday
        $daysCount = ($startDate->isSaturday() && $excludeSaturdays === true) || ($startDate->isSunday() && $excludeSundays === true) ? 0 : 1;

        // Iterate through each day between the start and end dates
        $currentDate = $startDate->copy();
        while ($currentDate->lt($endDate)) {
            $currentDate->addDay();
            if ($excludeSaturdays && $currentDate->isSaturday()) {
                continue;
            }
            if ($excludeSundays && $currentDate->isSunday()) {
                continue;
            }
            $daysCount++;
        }

        return $daysCount;
    }

    // #[Computed]
    // public function projects()
    // {
    //     return Project::with('tasks')
    //         ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
    //         ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']]);
    // }

    #[Title('Planner')]
    public function render()
    {
        return view('livewire.planner.index');
    }
}
