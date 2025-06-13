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
    public $vendor_id = null;

    #[Validate('nullable|array')]
    public $user_ids = null;

    #[Validate('required')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    // Add weekend options
    public $saturday = false;
    public $sunday = false;

    public ?Task $task;

    public function setTask(Task $task)
    {
        $this->task = $task;
        $this->dates = ['start' => $task->start_date, 'end' => $task->end_date] ?? [];

        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->vendor_id = $task->vendor_id;
        $this->type = $task->type;
        $this->title = $task->title;
        $this->notes = $task->notes;
        $this->user_ids = $task->user_ids ?? [];

        // Load weekend options from database
        $this->saturday = $task->options->saturday ?? false;
        $this->sunday = $task->options->sunday ?? false;
    }

    public function update()
    {
        $this->authorize('update', $this->task);
        $this->validate();

        // Prepare options array
        $options = (array) ($this->task->options ?? []);

        // Update weekend options - only store true values
        if ($this->saturday) {
            $options['saturday'] = true;
        } else {
            unset($options['saturday']);
        }

        if ($this->sunday) {
            $options['sunday'] = true;
        } else {
            unset($options['sunday']);
        }

        $this->task->update([
            'start_date' => $this->dates['start'] ?? null,
            'end_date' => $this->dates['end'] ?? null,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => $this->order,
            'options' => $options,
        ]);

        return $this->task;
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        // Prepare options array
        $options = [];

        // Only store true values for weekend options
        if ($this->saturday) {
            $options['saturday'] = true;
        }

        if ($this->sunday) {
            $options['sunday'] = true;
        }

        $task = Task::create([
            'start_date' => $this->dates['start'] ?? null,
            'end_date' => $this->dates['end'] ?? null,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => 0,
            'options' => $options,
        ]);

        return $task;
    }
}
