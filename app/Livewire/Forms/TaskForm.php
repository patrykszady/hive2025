<?php

namespace App\Livewire\Forms;

use App\Models\Task;
use Livewire\Attributes\Validate;
use Livewire\Form;

class TaskForm extends Form
{
    public ?Task $task;

    #[Validate('required')]
    public $title = null;

    #[Validate('array')]
    public $dates = [];

    #[Validate('required')]
    public $project_id = null;

    #[Validate('nullable')]
    public $order = null;

    #[Validate('nullable')]
    public $vendor_id = null;

    #[Validate('nullable|array')]
    public $user_ids = null;

    #[Validate('required')]
    public $type = null;

    #[Validate('nullable')]
    public $notes = null;

    #[Validate('nullable|exists:tasks,id')]
    public $parent_task_id = null;

    public $saturday = false;
    public $sunday = false;

    public function setTask(Task $task)
    {
        $this->task = $task;

        $this->title = $task->title;
        $this->dates = [
            'start' => $task->start_date?->format('Y-m-d'),
            'end' => $task->end_date?->format('Y-m-d'),
        ];
        $this->project_id = $task->project_id;
        $this->order = $task->order;
        $this->vendor_id = $task->vendor_id;
        $this->user_ids = $task->user_ids;
        $this->type = $task->type;
        $this->notes = $task->notes;
        $this->parent_task_id = $task->parent_task_id;

        // Set weekend options
        $this->saturday = $task->options?->saturday ?? false;
        $this->sunday = $task->options?->sunday ?? false;
    }

    public function update()
    {
        $this->validate();

        // Custom validation for sibling overlaps
        $startDate = $this->dates['start'] ?? null;
        $endDate = $this->dates['end'] ?? null;

        if ($startDate && $endDate && $this->task->wouldOverlapWithSiblings($startDate, $endDate)) {
            $this->addError('dates', 'This task would overlap with a sibling task.');
            return false;
        }

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
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => $this->order,
            'options' => $options,
            'parent_task_id' => $this->parent_task_id,
        ]);

        return $this->task;
    }

    public function store()
    {
        $this->validate();

        // Custom validation for sibling overlaps
        $startDate = $this->dates['start'] ?? null;
        $endDate = $this->dates['end'] ?? null;

        if ($startDate && $endDate && $this->parent_task_id) {
            $tempTask = new Task([
                'id' => 0,
                'project_id' => $this->project_id,
                'parent_task_id' => $this->parent_task_id,
            ]);

            if ($tempTask->wouldOverlapWithSiblings($startDate, $endDate)) {
                // Use 'dates' instead of 'form.dates'
                $this->addError('dates', 'This task would overlap with a sibling task.');
                return false;
            }
        }

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
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => $this->user_ids,
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => 0,
            'options' => $options,
            'parent_task_id' => $this->parent_task_id,
        ]);

        return $task;
    }
}
