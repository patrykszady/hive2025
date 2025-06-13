<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\GanttIndex;
use App\Livewire\Planner\CardsIndex;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Attributes\Computed;

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

    #[Computed]
    public function duration()
    {
        $startDate = $this->form->dates['start'] ?? null;
        $endDate = $this->form->dates['end'] ?? null;

        if (is_null($startDate) || is_null($endDate)) {
            return 0;
        }

        $period = CarbonPeriod::create($startDate, $endDate);
        $totalDays = 0;

        foreach ($period as $date) {
            $isSaturday = $date->isSaturday();
            $isSunday = $date->isSunday();

            // Include the day if:
            // - It's a weekday (not Saturday or Sunday)
            // - It's Saturday and Saturday is enabled
            // - It's Sunday and Sunday is enabled
            if ((!$isSaturday && !$isSunday) ||
                ($isSaturday && $this->form->saturday) ||
                ($isSunday && $this->form->sunday)) {
                $totalDays++;
            }
        }

        return $totalDays;
    }

    /**
     * Helper method to refresh all planner components
     */
    private function refreshPlannerComponents()
    {
        $this->dispatch('refreshComponent')->to(GanttIndex::class);
        $this->dispatch('refreshComponent')->to(CardsIndex::class);
    }

    public function updated($field, $value)
    {
        // No need to manually calculate duration anymore - it's computed automatically
        // The computed property will handle the weekend-aware calculation
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

        $this->refreshPlannerComponents();
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Removed',
            text: '',
        );
    }

    public function edit()
    {
        $this->form->update();
        $this->refreshPlannerComponents();
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Updated',
            text: '',
        );
    }

    public function save()
    {
        $this->form->store();
        $this->refreshPlannerComponents();
        $this->modal('task_create_form_modal')->close();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Created',
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
