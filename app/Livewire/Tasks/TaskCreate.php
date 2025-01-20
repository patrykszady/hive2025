<?php

namespace App\Livewire\Tasks;

use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\PlannerIndex;
use App\Models\Task;
use Carbon\Carbon;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class TaskCreate extends Component
{
    use AuthorizesRequests;

    public TaskForm $form;

    //$projects & $vendors & $employees come from the Planner Component
    public $projects = [];

    public $vendors = [];

    public $employees = [];

    public $view_text = [
        'card_title' => 'Create Task',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['editTask', 'addTask'];

    public function updated($field, $value)
    {
        if (! is_null($this->form->start_date)) {
            $startDate = Carbon::parse($this->form->start_date);
            $endDate = Carbon::parse($this->form->end_date);

            $excludeSaturdays = ! isset($this->form->include_weekend_days['saturday']) || $this->form->include_weekend_days['saturday'] === false;
            $excludeSundays = ! isset($this->form->include_weekend_days['sunday']) || $this->form->include_weekend_days['sunday'] === false;
            $duration = $this->countDaysBetweenDates($startDate, $endDate, $excludeSaturdays, $excludeSundays);

            $this->form->duration = $duration;
        }

        // $this->validateOnly($field);
    }

    //Copilot help
    //2024-12-10 SAME ON PlannerIndex
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

    public function addTask($project_id, $date = null)
    {
        $this->form->reset();
        $this->resetErrorBag();

        $this->view_text = [
            'card_title' => 'Create Task',
            'button_text' => 'Create',
            'form_submit' => 'save',
        ];

        if ($date) {
            $this->form->dates = [Carbon::parse($date)->format('m/d/Y')];
        } else {
            $this->form->dates = [];
        }

        $this->form->project_id = $project_id;
        $this->modal('task_create_form_modal')->show();
    }

    public function editTask(Task $task)
    {
        $this->resetErrorBag();

        $this->view_text = [
            'card_title' => 'Edit Task',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->form->setTask($task);
        $this->modal('task_create_form_modal')->show();
    }

    public function removeTask()
    {
        $task = $this->form->task;
        $task->delete();

        $this->dispatch('refreshComponent')->to(PlannerIndex::class);
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Removed',
            // route / href / wire:click
            text: '',
        );
    }

    // 5-7-2024 for flatpickr only... anyway to optimize?
    public function dateschanged($dates)
    {
        $this->form->dates = $dates;

        if (count($dates) > 1) {
            $start = Carbon::parse($dates[0]);
            $end = Carbon::parse($dates[1]);

            $duration = $end->diff($start)->days + 1;

            $this->form->duration = $duration;
        } elseif (empty($dates[0])) {
            $this->form->duration = 0;
        } else {
            $this->form->duration = 1;
        }
    }

    public function save()
    {
        $this->form->store();
        $this->dispatch('refreshComponent')->to(PlannerIndex::class);
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Created',
            // route / href / wire:click
            text: '',
        );
    }

    public function edit()
    {
        $this->form->update();
        $this->dispatch('refreshComponent')->to(PlannerIndex::class);
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Updated',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.tasks.create');
    }
}
