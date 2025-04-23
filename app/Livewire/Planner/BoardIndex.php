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
    public function render()
    {
        // Calculate the days for the current week
        $monday = $this->week ? Carbon::parse($this->week)->startOfWeek() : Carbon::now()->startOfWeek();
        $this->days = collect(CarbonPeriod::create($monday, '1 day', $monday->copy()->addDays(20)));

        $days = $this->days->map(function ($day) {
            return $day->format('Y-m-d');
        })->toArray();

        $this->projects = Project::query()
            ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
            ->get();

        // Group tasks by day for each project, accounting for multi-day tasks
        $this->projects->each(function ($project) use ($days) {
            $groupedTasks = collect($days)->mapWithKeys(function ($day) {
                return [$day => collect()]; // Initialize empty collections for each day
            });

            $project->tasks()
                ->orderBy('start_date', 'asc')
                ->get()
                ->each(function ($task) use ($groupedTasks) {
                    if ($task->start_date && $task->end_date) {
                        // Get the range of days the task spans
                        $taskPeriod = CarbonPeriod::create(
                            $task->start_date->format('Y-m-d'),
                            $task->end_date->format('Y-m-d')
                        );

                        // Check options for including weekends
                        $includeSaturday = $task->options->include_weekend_days->saturday ?? false;
                        $includeSunday = $task->options->include_weekend_days->sunday ?? false;

                        foreach ($taskPeriod as $date) {
                            $dayOfWeek = $date->dayOfWeek; // 6 = Saturday, 0 = Sunday

                            // Skip weekends if not included in options
                            if (($dayOfWeek === 6 && !$includeSaturday) || ($dayOfWeek === 0 && !$includeSunday)) {
                                continue;
                            }

                            $formattedDate = $date->format('Y-m-d');
                            if ($groupedTasks->has($formattedDate)) {
                                $groupedTasks[$formattedDate]->push($task);
                            }
                        }
                    } elseif ($task->start_date) {
                        // If the task only has a start_date, assign it to that day
                        $formattedDate = $task->start_date->format('Y-m-d');
                        if ($groupedTasks->has($formattedDate)) {
                            $groupedTasks[$formattedDate]->push($task);
                        }
                    }
                });

            $project->setAttribute('grouped_tasks', $groupedTasks);
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
            'fullscreenClasses' => 'relative overflow-y-auto h-full p-0! lg:p-0!',
        ]);
    }
}
