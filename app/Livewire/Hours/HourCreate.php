<?php

namespace App\Livewire\Hours;

use App\Livewire\Forms\HourForm;
use App\Models\Hour;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;

use Illuminate\Support\Carbon;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;

use Flux;

use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class HourCreate extends Component
{
    public HourForm $form;

    public $projects = [];
    // public $other_projects = [];
    public Carbon $selected_date;
    public $days = [];


    public $hours_count_store = 0;

    public $day_index = null;

    public $new_project_id = null;

    public $day_project_tasks = [];

    public $view_text = [
        'card_title' => 'Create Daily Hours',
        'button_text' => 'Add Daily Hours',
        'form_submit' => 'save',
    ];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function rules()
    {
        return [
            'selected_date' => 'nullable',
            'new_project_id' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->selectedDate(today());

        $confirmed_weeks =
            Timesheet::orderBy('date', 'DESC')
                ->where('user_id', auth()->user()->id)
                ->where('date', '>', today()->subWeeks(8))
                ->get()
                ->groupBy('date');

        if (! $confirmed_weeks->isEmpty()) {
            foreach ($confirmed_weeks as $confirmed_week) {
                $week_days = new \DatePeriod(
                    $confirmed_week->first()->date->startOfWeek(Carbon::MONDAY),
                    CarbonInterval::day(),
                    $confirmed_week->first()->date->endOfWeek(Carbon::SUNDAY)
                );

                foreach ($week_days as $confirmed_date) {
                    $confirmed_week_days[] = $confirmed_date->format('Y-m-d');
                }
            }
        } else {
            $confirmed_week_days[] = null;
        }

        $this->days = implode(',', $confirmed_week_days);

        $this->form->setProjects($this->projects->toArray());
    }

    public function updatedSelectedDate($value)
    {
        if($value){
            $this->selectedDate($value);
            $this->validate();
        }else{
            $this->selectedDate(today());
        }
    }

    #[Computed]
    public function other_projects()
    {
        $other_projects = Project::whereNotIn('id', $this->projects->pluck('id'))->whereYear('created_at', '>=', Carbon::now()->subYears(3)->year)->orderBy('created_at', 'DESC')->get();
        return $other_projects;
    }

    public function updated()
    {
        $this->validate();
    }

    public function getHoursCountProperty()
    {
        $this->hours_count_store = collect($this->form->projects)->where('hours', '!=', null)->sum('hours');

        return $this->hours_count_store;
    }

    public function selectedDate($date, $day_index = null)
    {
        if (!is_null($day_index)) {
            $this->day_index = $day_index;
            $new_date = $this->days[$day_index];

            $user_day_hours = Hour::where('user_id', auth()->user()->id)->where('date', $new_date['format'])->get();
            $has_hours = $user_day_hours->isEmpty() ? false : true;
            $this->days[$day_index]['has_hours'] = $has_hours;
        }

        $this->selected_date = $date;

        $user_day_hours = Hour::where('user_id', auth()->user()->id)
            ->where('date', $this->selected_date->format('Y-m-d'))
            ->get();

        $projects = Project::status(['Active'])->get();

        $planner_projects_day = Task::whereJsonContains('user_ids', auth()->user()->id)
            ->whereJsonContains('dates', $this->selected_date->format('Y-m-d')) // Use JSON column to filter tasks
            ->whereNotIn('project_id', $projects->pluck('id')->toArray())
            ->pluck('project_id')
            ->unique();

        // dd($planner_projects_day);

        $planner_projects_day = Project::whereIn('id', $planner_projects_day)->get();

        $other_projects = Project::whereIn('id', $user_day_hours->pluck('project_id')->unique())->get();

        $merged_projects = $projects->merge($other_projects)->merge($planner_projects_day);

        $this->projects = Project::whereIn('id', $merged_projects->pluck('id')->toArray())
            ->with(['tasks' => function ($query) {
                $query->whereJsonContains('user_ids', auth()->user()->id)
                    ->whereNotNull('dates') // Ensure tasks have dates
                    ->each(function ($task) {
                        foreach ($task->dates as $task_date) { // Iterate over the dates array
                            $this->day_project_tasks[$task->project->id][$task->id]['dates'][] = $task_date;
                            $this->day_project_tasks[$task->project->id][$task->id]['title'] = $task->title;
                        }
                    });
            }])
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.title', 'asc'],
                ['latestStatus.start_date', 'desc']
            ])
            ->keyBy('id');

        foreach ($this->day_project_tasks as $project_id => $project_tasks) {
            foreach ($project_tasks as $task_id => $task) {
                if (in_array($this->selected_date->format('Y-m-d'), $task['dates'])) {
                    // Task is valid for the selected date
                } else {
                    unset($this->day_project_tasks[$project_id][$task_id]);
                }
            }
        }

        $this->resetValidation();

        if ($user_day_hours->isEmpty()) {
            $this->view_text = [
                'card_title' => 'Create Daily Hours',
                'button_text' => 'Add Daily Hours',
                'form_submit' => 'save',
            ];
        } else {
            foreach ($this->projects as $index => $project) {
                $project_user_date = Hour::where('user_id', auth()->user()->id)
                    ->where('date', $date)
                    ->where('project_id', $project->id)
                    ->get();

                if (!$project_user_date->isEmpty()) {
                    $project->hours = $project_user_date->first()->hours;
                    $project->hour_id = $project_user_date->first()->id;
                }
            }

            $this->view_text = [
                'card_title' => 'Edit Daily Hours',
                'button_text' => 'Update Daily Hours',
                'form_submit' => 'edit',
            ];
        }

        $this->form->setProjects($this->projects->toArray());
    }

    public function add_project()
    {
        //return with error
        if (is_null($this->new_project_id)) {
            $this->addError('select_new_project', 'Please select another project.');
        } else {
            $project = $this->other_projects->where('id', $this->new_project_id);
            $this->projects->add($project->first());

            $this->form->projects[] = $project->first()->toArray();

            $this->other_projects->forget($project->keys()->first());
            $this->new_project_id = null;
            $this->render();
        }
    }

    public function save()
    {
        if ($this->hours_count_store == 0) {
            $this->addError('hours_count', 'Daily Hours need at least one entry.');
        } else {
            $this->form->store();
            $this->selectedDate($this->selected_date, $this->day_index);

            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'success',
                heading: 'Hours Added',
                // route / href / wire:click
                text: '',
            );
        }
    }

    public function edit()
    {
        $this->form->update();
        $this->selectedDate($this->selected_date, $this->day_index);

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Hours Updated',
            // route / href / wire:click
            text: '',
        );
    }

    #[Title('Hours')]
    public function render()
    {
        return view('livewire.hours.form');
    }
}
