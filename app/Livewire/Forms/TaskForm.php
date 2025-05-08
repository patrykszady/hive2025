<?php

namespace App\Livewire\Forms;

use App\Models\Task;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Form;

class TaskForm extends Form
{
    use AuthorizesRequests;

    #[Validate('required')]
    public $title = null;

    #[Validate('array')]
    public $dates = []; // Store dates as an array

    #[Validate('required')]
    public $project_id = null;

    #[Validate('nullable')]
    public $order = null;

    #[Validate('nullable')]
    public $duration = 0;

    #[Validate('nullable')]
    public $vendor_id = null;

    #[Validate('nullable')]
    public $user_id = null;

    #[Validate('required')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    public ?Task $task;

    public function setTask(Task $task)
    {
        $this->task = $task;
        $this->dates = $task->dates ?? []; // Load dates as an array
        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->duration = count($this->dates); // Calculate duration based on the number of dates
        $this->vendor_id = $task->vendor_id;
        $this->type = $task->type;
        $this->title = $task->title;
        $this->notes = $task->notes;
        $this->user_id = $task->user_id;
    }

    public function update()
    {
        $this->authorize('update', $this->task);
        $this->validate();

        $this->task->update([
            'dates' => $this->dates, // Save dates as JSON
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'duration' => count($this->dates), // Update duration based on the number of dates
            'order' => $this->order,
        ]);

        return $this->task;
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        $task = Task::create([
            'dates' => $this->dates, // Save dates as JSON
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => 0,
            'duration' => count($this->dates), // Calculate duration based on the number of dates
        ]);

        return $task;
    }
}
