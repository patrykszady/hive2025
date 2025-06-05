<?php

namespace App\Livewire\Forms;

use App\Models\Task;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;

use Carbon\CarbonPeriod;

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

    #[Validate('nullable|array')]
    public $user_ids = null;

    #[Validate('required')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    public ?Task $task;

    public function setTask(Task $task)
    {
        $this->task = $task;
        $this->dates = ['start' => $task->start_date, 'end' => $task->end_date] ?? []; // Load dates as an array

        $startDate = $this->dates['start'] ?? NULL; // Start date
        $endDate = $this->dates['end'] ?? NULL; // End date

        if(!is_null($startDate) && !is_null($endDate)) {
            $period = CarbonPeriod::create($startDate, $endDate);
            $duration = iterator_count($period);
        }else{
            $duration = 0;
        }

        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->duration = $duration;
        $this->vendor_id = $task->vendor_id;
        $this->type = $task->type;
        $this->title = $task->title;
        $this->notes = $task->notes;
        $this->user_ids = $task->user_ids ?? []; // Load user_ids as an array
    }

    public function update()
    {
        $this->authorize('update', $this->task);
        $this->validate();

        $this->task->update([
            // 'dates' => $this->dates, // Save dates as JSON
            'start_date' => $this->dates['start'], // Save start date
            'end_date' => $this->dates['end'], // Save end date
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => $this->order,
        ]);

        return $this->task;
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        $task = Task::create([
            // 'dates' => $this->dates, // Save dates as JSON
            'start_date' => $this->dates['start'], // Save start date
            'end_date' => $this->dates['end'], // Save end date
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => 0,
        ]);

        return $task;
    }
}
