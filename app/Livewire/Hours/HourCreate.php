<?php

namespace App\Livewire\Hours;

use App\Livewire\Forms\HourForm;
use App\Models\Hour;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;

use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class HourCreate extends Component
{
    use AuthorizesRequests;
    
    public HourForm $form;

    public $projects = [];
    public ?Carbon $selected_date = null;
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
        $this->authorize('create', Hour::class);
        
        // Initialize to null - will be set by setBrowserDate() called from the view
        $this->selected_date = null;

        $confirmed_weeks =
            Timesheet::orderBy('date', 'DESC')
                ->where('user_id', auth()->user()->id)
                ->where('date', '>', Carbon::today(config('app.timezone'))->subWeeks(8))
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

        // Don't set projects here - wait for setBrowserDate to be called
        // which will call selectedDate() and populate projects
    }
    
    public function setBrowserDate($browserDate)
    {
        // Always initialize with browser's current date (not server's UTC date)
        $this->selectedDate($browserDate);
        
        // Now set projects after selectedDate has been called
        if (is_array($this->projects)) {
            $this->form->setProjects($this->projects);
        } else {
            $this->form->setProjects($this->projects->toArray());
        }
    }

    public function updatedSelectedDate($value)
    {
        if($value){
            $this->selectedDate($value);
            $this->validate();
        }else{
            $this->selectedDate(Carbon::today(config('app.timezone')));
        }
    }

    #[Computed]
    public function other_projects()
    {
        // Handle case where projects is still an empty array before setBrowserDate() runs
        $projectIds = is_array($this->projects) 
            ? collect($this->projects)->pluck('id') 
            : $this->projects->pluck('id');
            
        $other_projects = Project::whereNotIn('id', $projectIds)->whereYear('created_at', '>=', Carbon::now()->subYears(3)->year)->orderBy('created_at', 'DESC')->get();
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

        // Parse the date as-is (comes from browser in Y-m-d format, represents the user's local date)
        $this->selected_date = Carbon::parse($date)->startOfDay();

        // Use the date in Y-m-d format for database queries (dates are stored without timezone)
        $dateForQuery = $this->selected_date->format('Y-m-d');

        $user_day_hours = Hour::where('user_id', auth()->user()->id)
            ->where('date', $dateForQuery)
            ->get();

        // Get Active and Service Call projects (using status codes: 6 = Active, 8 = Service Call)
        $projects = Project::status([6, 8])->get();

        // Get projects that have tasks for the selected date (without user filter for now)
        $planner_projects_day = Task::where('start_date', '<=', $dateForQuery)
            ->where('end_date', '>=', $dateForQuery)
            ->pluck('project_id')
            ->unique();

        $planner_projects_day = Project::whereIn('id', $planner_projects_day)->get();

        $other_projects = Project::whereIn('id', $user_day_hours->pluck('project_id')->unique())->get();

        $merged_projects = $projects->merge($other_projects)->merge($planner_projects_day);

        // Load projects with ALL tasks (we'll filter after)
        $this->projects = Project::whereIn('id', $merged_projects->pluck('id')->toArray())
            ->with(['tasks' => function ($query) use ($dateForQuery) {
                $query->where('start_date', '<=', $dateForQuery)
                    ->where('end_date', '>=', $dateForQuery);
            }])
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.title', 'asc'],
                ['latestStatus.start_date', 'desc']
            ])
            ->keyBy('id');

        // Clear the day_project_tasks array
        $this->day_project_tasks = [];

        // Now filter tasks for the current user
        foreach ($this->projects as $project) {
            foreach ($project->tasks as $task) {
                // user_ids should already be an array due to model casting
                $userIds = $task->user_ids ?? [];

                if (in_array(auth()->user()->id, $userIds) || in_array((string)auth()->user()->id, $userIds)) {
                    $this->day_project_tasks[$project->id][] = [
                        'id' => $task->id,
                        'title' => $task->title,
                        'start_date' => $task->start_date,
                        'end_date' => $task->end_date,
                    ];
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
                    ->where('date', $dateForQuery)
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

    public function incrementHours($index)
    {
        $currentHours = $this->form->projects[$index]['hours'] ?? 0;
        if ($currentHours < 16) {
            $this->form->projects[$index]['hours'] = $currentHours + 1;
        }
    }

    public function decrementHours($index)
    {
        $currentHours = $this->form->projects[$index]['hours'] ?? 0;
        if ($currentHours >= 1) {
            $this->form->projects[$index]['hours'] = $currentHours - 1;
        }
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
        $this->authorize('create', Hour::class);
        return view('livewire.hours.form');
    }
}
