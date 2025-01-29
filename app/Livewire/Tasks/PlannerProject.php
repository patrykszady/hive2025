<?php

namespace App\Livewire\Tasks;

use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Livewire\Component;

class PlannerProject extends Component
{
    // public Project $project;
    public $days = [];

    public $projects = [];

    protected $listeners = ['refresh_planner'];

    public function mount()
    {
        $project_ids = $this->projects->pluck('id');

        $tasks =
            Task::whereIn('project_id', $project_ids)
                ->where(function ($query) {
                    $query->whereBetween('start_date', [$this->days[0]['database_date'], $this->days[6]['database_date']])
                        ->orWhereBetween('end_date', [$this->days[0]['database_date'], $this->days[6]['database_date']]);
                })->orWhere(function ($query) {
                    $query->whereDate('start_date', '<=', $this->days[0]['database_date'])
                        ->whereDate('end_date', '>=', $this->days[6]['database_date']);
                })
                ->get()
                ->each(function ($task) {
                    if ($task->start_date->between($this->days[0]['database_date'], $this->days[6]['database_date']) && $task->end_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                        $task->date = $task->start_date->format('Y-m-d');
                        $task->direction = 'left';
                    } elseif ($task->start_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                        $task->date = $task->start_date->format('Y-m-d');
                        $task->direction = 'left';
                    } elseif ($task->end_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                        $task->date = $task->end_date->format('Y-m-d');
                        $task->direction = 'right';
                    } else {
                        //if going from a previous week, via this week, and to the next
                        $task->date = $this->days[6]['database_date'];
                        $task->direction = 'right';
                    }
                })
                ->groupBy('project_id');
        // dd($tasks);
        $no_date_tasks =
            Task::whereIn('project_id', $project_ids)
                ->where(function ($query) {
                    $query->where('vendor_id', auth()->user()->vendor->id)->orWhere('belongs_to_vendor_id', auth()->user()->vendor->id)->orWhere('created_by_user_id', auth()->user()->vendor->users()->employed()->pluck('users.id')->toArray());
                })
                ->whereNull('start_date')
                ->get()
                ->groupBy('project_id');

        // Combine projects and tasks
        foreach ($this->projects as $project_index => $project) {
            $this->projects[$project_index]->tasks = $tasks[$project->id] ?? collect();
            $this->projects[$project_index]->no_date_tasks = $no_date_tasks[$project->id] ?? collect();
        }
    }

    public function taskMoved($items)
    {
        foreach ($items as $item) {
            $task = Task::findOrFail($item['task_id']);

            if (is_null($task->start_date)) {
                $days = collect($this->days);
                $task->start_date = $days[$item['x']]['database_date'];
                $task->end_date = $days[$item['x']]['database_date'];
                $task->save();
            }

            if ($task->start_date->between($this->days[0]['database_date'], $this->days[6]['database_date']) && $task->end_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                $task->date = $task->start_date;
                $task->direction = 'left';
            } elseif ($task->start_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                $task->date = $task->start_date;
                $task->direction = 'left';
            } elseif ($task->end_date->between($this->days[0]['database_date'], $this->days[6]['database_date'])) {
                $task->date = $task->end_date;
                $task->direction = 'right';
            }

            $days = collect($this->days);
            $day_index = $days->where('database_date', $task->date->format('Y-m-d'))->keys()->first();

            if ($task->direction == 'left' && 7 - $day_index < $task->duration) {
                $duration = ($day_index - $item['x']) + $task->duration;
            } elseif ($task->direction == 'right') {
                $duration = $task->duration + $item['w'] - ($day_index + 1);
            } else {
                $duration = $item['w'];
            }

            $task->duration = $duration;
            $task->order = $item['y'];

            if ($task->direction == 'left') {
                $task->start_date = $this->days[$item['x']]['database_date'];
                $task->end_date = Carbon::parse($this->days[$item['x']]['database_date'])->addDays($duration - 1)->format('Y-m-d');
            } else {
                $task->end_date = $task->start_date->addDays($duration - 1)->format('Y-m-d');
            }

            unset($task->date);
            unset($task->direction);
            $task->save();
        }

        $this->mount();

        $this->dispatch('notify',
            type: 'success',
            content: 'Task Moved'
        );
    }

    public function refresh_planner($days = null)
    {
        if (! is_null($days)) {
            $this->days = $days;
        }

        $this->mount();
    }

    public function render()
    {
        return view('livewire.tasks.planner-project');
    }
}
