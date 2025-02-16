<?php

namespace App\Livewire\Hours;

use App\Livewire\Forms\HourForm;
use App\Models\Hour;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Livewire\Attributes\Title;
use Livewire\Component;

class HourCreate extends Component
{
    public HourForm $form;

    public $projects = [];

    public $other_projects = [];

    public $days = [];

    public $hours_count_store = 0;

    public $selected_date = null;

    public $day_index = null;

    public $new_project_id = null;

    public $day_project_tasks = [];

    public $view_text = [
        'card_title' => 'Create Daily Hours',
        'button_text' => 'Add Daily Hours',
        'form_submit' => 'save',
    ];

    protected $listeners = ['refreshComponent' => '$refresh', 'selectedDate'];

    public function rules()
    {
        return [
            'new_project_id' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->selectedDate(today()->format('Y-m-d'));
        $this->other_projects = Project::whereNotIn('id', $this->projects->pluck('id'))->orderBy('created_at', 'DESC')->get();

        $confirmed_weeks =
            Timesheet::orderBy('date', 'DESC')
                ->where('user_id', auth()->user()->id)
                ->where('date', '>', today()->subWeeks(4))
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

        $this->days = collect();
        foreach ($this->getDays() as $day) {
            $user_day_hours = Hour::where('user_id', auth()->user()->id)->where('date', $day->format('Y-m-d'))->get();

            $this->days->push(collect([
                'format' => $day->format('Y-m-d'),
                'day' => $day->format('d'),
                'month' => $day->format('m'),
                'has_hours' => $user_day_hours->isEmpty() ? false : true,
                'confirmed_date' => in_array($day->format('Y-m-d'), $confirmed_week_days) ? true : false,
            ]));
        }

        $this->form->setProjects($this->projects->toArray());
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

    public function getDays()
    {
        return new \DatePeriod(
            Carbon::parse('3 weeks ago')->startOfWeek(Carbon::MONDAY),
            CarbonInterval::day(),
            Carbon::parse('1 week')->startOfWeek(Carbon::MONDAY)->next('Week')
        );
    }

    public function selectedDate($date, $day_index = null)
    {
        if (! is_null($day_index)) {
            $this->day_index = $day_index;
            $new_date = $this->days[$day_index];

            $user_day_hours = Hour::where('user_id', auth()->user()->id)->where('date', $new_date['format'])->get();
            $has_hours = $user_day_hours->isEmpty() ? false : true;
            $this->days[$day_index]['has_hours'] = $has_hours;
        }

        //if current User doesnt have any hours for this date let them add new project, if they do let them edit if not yet paid (or timesheet created)
        $this->selected_date = Carbon::parse($date);
        $user_day_hours = Hour::where('user_id', auth()->user()->id)->where('date', $this->selected_date->format('Y-m-d'))->get();
        $projects = Project::status(['Active', 'Service Call']);
        $planner_projects_day =
            Task::where('user_id', auth()->user()->id)->whereNotNull('start_date')
                ->whereNotIn('project_id', $projects->pluck('id')->toArray())
                ->whereDate('start_date', '>=', $this->selected_date->format('Y-m-d'))
                ->whereDate('end_date', '<=', $this->selected_date->format('Y-m-d'))
                ->pluck('project_id')->unique('project_id');

        $planner_projects_day = Project::whereIn('id', $planner_projects_day)->get();
        $other_projects = Project::whereIn('id', $user_day_hours->pluck('project_id'))->get();

        $merged_projects = $projects->merge($other_projects);
        $merged_projects = $merged_projects->merge($planner_projects_day);

        $this->projects =
            Project::whereIn('id', $merged_projects->pluck('id')->toArray())->with(['tasks' => function ($query) {
                //CarbonPeriod between each task->start and end_date ... if $this->selected_date->format('Y-m-d') is between Carbon Period
                $query->where('user_id', auth()->user()->id)->whereNotNull('start_date')
                    ->each(function ($task) {
                        $task_duration_days = CarbonPeriod::create($task->start_date, $task->end_date);

                        foreach ($task_duration_days as $task_day) {
                            $this->day_project_tasks[$task->project->id][$task->id]['dates'][] = $task_day->format('Y-m-d');
                            $this->day_project_tasks[$task->project->id][$task->id]['title'] = $task->title;
                        }
                    });
            }])
                ->get()
                ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']])
                ->keyBy('id');

        foreach ($this->day_project_tasks as $project_id => $project_tasks) {
            foreach ($project_tasks as $task_id => $task) {
                if (in_array($this->selected_date->format('Y-m-d'), $task['dates'])) {

                } else {
                    //remove $task from array
                    unset($this->day_project_tasks[$project_id][$task_id]);
                }
            }
        }

        // dd($this->day_project_tasks);
        $this->resetValidation();

        if ($user_day_hours->isEmpty()) {
            $this->view_text = [
                'card_title' => 'Create Daily Hours',
                'button_text' => 'Add Daily Hours',
                'form_submit' => 'save',
            ];
        } else {
            //insert hours into the projects_id array
            foreach ($this->projects as $index => $project) {
                $project_user_date = Hour::where('user_id', auth()->user()->id)->where('date', $date)->where('project_id', $project->id)->get();
                if ($project_user_date->isEmpty()) {

                } else {
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
        // dd(collect($this->form->projects));
        // dd($this->form->projects[250]->hours);
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
            $this->selectedDate($this->selected_date->format('Y-m-d'), $this->day_index);
            $this->dispatch('notify',
                type: 'success',
                content: 'Hours Created'
            );
        }
    }

    public function edit()
    {
        // if($this->hours_count_store == 0){
        //     $this->addError('hours_count', 'Daily Hours need at least one entry.');
        // }else{
        //     $this->form->update();
        //     $this->selectedDate($this->selected_date->format('Y-m-d'), $this->day_index);
        // }
        $this->form->update();
        $this->selectedDate($this->selected_date->format('Y-m-d'), $this->day_index);

        $this->dispatch('notify',
            type: 'success',
            content: 'Hours Updated'
        );
    }

    #[Title('Hours')]
    public function render()
    {
        return view('livewire.hours.form');
    }
}
