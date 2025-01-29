<?php

namespace App\Livewire\Planner;

use App\Livewire\Forms\TaskForm;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Carbon\Carbon;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PlannerCard extends Component
{
    public Project $project;

    public TaskForm $form;

    public $task_date = null;

    //comes from PlannerIndex
    public $projects = [];

    public $vendors = [];

    public $employees = [];

    public $view_text = [
        'card_title' => 'Create Task',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    // public function add()
    // {
    //     $this->query()->create([
    //         'title' => $this->pull('draft'),
    //         'type' => 'Task',
    //         'duration' => 1,
    //     ]);
    // }

    public function mount()
    {
        $this->form->project_id = $this->project->id;
        // $this->vendors = Vendor::whereNot('business_type', 'Retail')->get();
        // $this->employees = auth()->user()->vendor->users()->employed()->get();
    }

    public function form_modal(Task $task)
    {
        if ($task->id) {
            $this->form->setTask($task);
            $this->view_text = [
                'card_title' => 'Edit Task',
                'button_text' => 'Update',
                'form_submit' => 'edit',
            ];
        } else {
            $this->view_text = [
                'card_title' => 'Create Task',
                'button_text' => 'Create',
                'form_submit' => 'save',
            ];
        }

        $this->modal('task_create_form_modal')->show();
    }

    public function sort($key, $position)
    {
        $task = Task::findOrFail($key);

        // If this Task does not belong to this Project,
        if ($task->project->isNot($this->project)) {
            $task->displace();
            $task->project()->associate($this->project);
        }

        $task->start_date = $this->task_date;
        $task_days_count = $task->duration;

        if (in_array($task_days_count, [0, 1])) {
            $task->end_date = $task->start_date;
            $task->duration = 1;
        } else {
            $task->end_date = Carbon::parse($task->start_date)->addDays($task_days_count - 1)->format('Y-m-d');
        }

        $task->save();
        $task->move($position);

        Flux::toast(
            duration: 1000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Moved',
            // route / href / wire:click
            text: '',
        );
    }

    #[Computed]
    public function tasks()
    {
        // return $this->query()->get();
        $task_date = Carbon::parse($this->task_date);

        return $this->query()->get()->filter(function ($item) use ($task_date) {
            if (is_null($item->start_date) && $this->task_date == null) {
                return $item;
            } elseif ($task_date->between($item->start_date, $item->end_date) && $this->task_date != null) {
                // dd(is_null($item->options->));
                // dd($item->start_date->isSaturday());
                // if(isset($item->options['include_weekend_days'])){
                //     if($task_date->isSaturday() && $item->options['include_weekend_days']['saturday'] == true){
                //         return $item;
                //     }elseif($task_date->isSaturday() && $item->options['include_weekend_days']['saturday'] == false){

                //     }else{
                //         if($task_date->isSunday() && $item->options['include_weekend_days']['sunday'] == true){
                //             return $item;
                //         }elseif($task_date->isSunday() && $item->options['include_weekend_days']['sunday'] == false){

                //         }else{
                //             return $item;
                //         }
                //     }
                //     // dd($item->options['include_weekend_days']);
                // }else{
                //     return $item;
                // }
                return $item;
            }
        });
    }

    // protected
    public function query()
    {
        return $this->project->tasks();
    }

    public function edit()
    {
        $this->authorize('update', $this->form->task);

        $task = $this->form->update();

        $task->move(0);

        $this->render();

        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 2000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Updated',
            // route / href / wire:click
            text: '',
        );
    }

    public function save()
    {
        // $this->authorize('create', Task::class);
        $this->form->store();
        $this->form->reset();
        $this->form->project_id = $this->project->id;
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 2000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Created',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.planner.card');
    }
}
