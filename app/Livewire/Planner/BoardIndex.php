<?php

namespace App\Livewire\Planner;

use App\Models\Project;
use App\Models\Vendor;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;

class BoardIndex extends Component
{
    public $projects = [];
    public $vendors = [];
    public $employees = [];

    public $days = [];
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
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

    public function sort($key, $position, $project_id, $date_index)
    {
        dd($key, $position, $project_id, $date_index);
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

    public function render()
    {
        // Calculate the days for the current week
        $monday = $this->week ? Carbon::parse($this->week)->startOfWeek() : Carbon::now()->startOfWeek();
        $this->days = collect(CarbonPeriod::create($monday, '1 day', $monday->copy()->addDays(20)));

        $days = $this->days->map(function ($day) {
            return $day->format('Y-m-d');
        })->toArray();

        $this->projects = Project::query()
            ->with('tasks')
            ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
            ->get();

        // Group tasks by day for each project, and store "No Date" tasks separately
        $this->projects->each(function ($project) use ($days) {
            $groupedTasks = collect($days)->mapWithKeys(function ($day) {
                return [$day => collect()]; // Initialize empty collections for each day
            });

            $noDateTasks = collect(); // Initialize a collection for "No Date" tasks

            $project->tasks()
                ->orderBy('created_at', 'asc') // Order tasks by creation date
                ->get()
                ->each(function ($task) use ($groupedTasks, $noDateTasks) {
                    if (!empty($task->dates)) {
                        foreach ($task->dates as $date) {
                            if ($groupedTasks->has($date)) {
                                $groupedTasks[$date]->push($task);
                            }
                        }
                    } else {
                        // If the task has no dates, add it to the "No Date" collection
                        $noDateTasks->push($task);
                    }
                });

            $project->grouped_tasks = $groupedTasks;
            $project->no_date = $noDateTasks; // Assign "No Date" tasks to a separate attribute
        });

        // Calculate the maximum number of tasks for each date across all projects
        $maxTasksPerDate = collect($days)->mapWithKeys(function ($day) {
            return [$day => collect($this->projects)->map(function ($project) use ($day) {
                return $project->grouped_tasks->has($day) ? $project->grouped_tasks[$day]->count() : 0;
            })->max()];
        });

        // Normalize grouped_tasks for each project
        $this->projects->each(function ($project) use ($maxTasksPerDate, $days) {
            $project->grouped_tasks = collect($days)->mapWithKeys(function ($date) use ($project, $maxTasksPerDate) {
                $tasks = $project->grouped_tasks->get($date, collect());
                $maxTasks = $maxTasksPerDate->get($date, 0);
                $placeholders = $maxTasks - $tasks->count();

                // Add placeholders for missing tasks
                for ($i = 0; $i < $placeholders; $i++) {
                    $tasks->push(null); // Add a null placeholder
                }

                return [$date => $tasks];
            });
        });

        return view('livewire.planner.board', [
            'projects' => $this->projects,
            'days' => $this->days,
            'maxTasksPerDate' => $maxTasksPerDate, // Pass $maxTasksPerDate to the view
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-y-auto',
        ]);
    }
}
