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

    // #[Validate('array')]
    // |date_format:Y-m-d|before_or_equal:end_date')]
        // #[Validate('nullable|date_format:Y-m-d|after_or_equal:start_date')]
    #[Validate('nullable')]
    public $dates = null;

    #[Validate('required')]
    public $project_id = null;

    #[Validate('nullable')]
    public $duration = 0;

    #[Validate('nullable')]
    public $order = null;

    #[Validate('nullable')]
    public $vendor_id = null;

    #[Validate('nullable')]
    public $user_id = null;

    #[Validate('required')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    public $include_weekend_days = [];

    public ?Task $task;

    public function rules()
    {
        return [
            'include_weekend_days.*' => 'nullable', // multiple checkbox
        ];
    }

    public function setTask(Task $task)
    {
        $this->task = $task;
        $this->start_date = $task->start_date ? $task->start_date : null;
        $this->end_date = $task->end_date ? $task->end_date : null;
        $this->include_weekend_days = (array) $task->options->include_weekend_days;
        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->duration = $task->duration;
        $this->vendor_id = $task->vendor_id;
        $this->type = $task->type;
        $this->title = $task->title;
        $this->notes = $task->notes;
        $this->user_id = $task->user_id;

        $this->dates = [
            'start' => $task->start_date ? $task->start_date->format('Y-m-d') : null,
            'end' => $task->end_date ? $task->end_date->format('Y-m-d') : null,
        ];
    }

    public function update()
    {
        $this->authorize('update', $this->task);
        $this->validate();

        $this->task->update([
            'start_date' => $this->dates['start'],
            'end_date' => $this->dates['end'],
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'options->include_weekend_days' => $this->include_weekend_days,
            'duration' => $this->duration,
            'order' => $this->order,
        ]);

        return $this->task;
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        $task = Task::create([
            'start_date' => $this->dates['start'],
            'end_date' => $this->dates['end'],
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'options->include_weekend_days' => $this->include_weekend_days,
            'order' => 0,
            'duration' => $this->duration,
        ]);

        return $task;
    }
}
