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
    // public $dates = NULL;
    // |date_format:Y-m-d|before_or_equal:end_date')]
    #[Validate('nullable')]
    public $start_date = null;

    // #[Validate('nullable|date_format:Y-m-d|after_or_equal:start_date')]
    #[Validate('nullable')]
    public $end_date = null;

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
        // if(!isset($task->start_date)){
        //     $new_dates = [];
        // }elseif($task->start_date === $task->end_date OR is_null($task->end_date)){
        //     $new_dates = [$task->start_date->format('m/d/Y')];
        // }else{
        //     $new_dates = [$task->start_date->format('m/d/Y'), $task->end_date->format('m/d/Y')];
        // }
        // if(!isset($task->start_date)){
        //     $new_dates = [];
        // }else{
        //     $new_dates = [$task->start_date->format('m/d/Y'), $task->end_date->format('m/d/Y')];
        // }
        // $this->dates = $new_dates;

        $this->start_date = $task->start_date ? $task->start_date->format('Y-m-d') : null;
        $this->end_date = $task->end_date ? $task->end_date->format('Y-m-d') : null;
        $this->include_weekend_days = (array) $task->options->include_weekend_days;
        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->duration = $task->duration;
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
            // 'start_date' => isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL,
            // 'start_date' => isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL,
            // // 'end_date' => isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL,
            // 'end_date' => isset($this->dates[1]) ? $this->dates[1] : (isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL),
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
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
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            // 'start_date' => isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL,
            // 'end_date' => isset($this->dates[0]) ? (!empty($this->dates[0]) ? $this->dates[0] : NULL) : NULL,
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
