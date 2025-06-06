<?php

namespace App\Livewire\Tasks;

use App\Models\Task;

use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\PlannerIndex;
use App\Livewire\Planner\GanttIndex;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
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
        if (!empty($this->form->dates)) {
            $startDate = $this->form->dates['start']; // Start date
            $endDate = $this->form->dates['end']; // End date

            // Create a CarbonPeriod instance
            $period = CarbonPeriod::create($startDate, $endDate);

            // Count the number of days
            $this->form->duration = iterator_count($period);
        }
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

        $this->form->dates = $date ? [Carbon::parse($date)->format('Y-m-d')] : [];
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

        // Emit event that modal opened successfully
        $this->dispatch('task-modal-opened');
    }

    public function removeTask()
    {
        $task = $this->form->task;
        $task->delete();

        $this->dispatch('refreshComponent')->to(GanttIndex::class);
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

    public function edit()
    {
        $this->form->update();
        $this->dispatch('refreshComponent')->to(GanttIndex::class);
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

    public function save()
    {
        $this->form->store();
        $this->dispatch('refreshComponent')->to(GanttIndex::class);
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

    // When modal closes
    public function closeModal()
    {
        // Your existing close logic...

        // Emit event that modal closed
        $this->dispatch('task-modal-closed');
    }

    public function render()
    {
        return view('livewire.tasks.create');
    }
}
