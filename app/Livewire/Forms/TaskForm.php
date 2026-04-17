<?php

namespace App\Livewire\Forms;

use App\Models\User;
use App\Models\Task;
use Livewire\Attributes\Validate;
use Livewire\Form;

class TaskForm extends Form
{
    public ?Task $task = null;

    public ?int $task_id = null;

    #[Validate('required')]
    public $title = null;

    #[Validate('array')]
    public $dates = [];

    #[Validate('nullable|array')]
    public $time_settings = [];

    #[Validate('required')]
    public $project_id = null;

    #[Validate('nullable')]
    public $order = null;

    #[Validate('nullable')]
    public $vendor_id = null;

    #[Validate('nullable|array')]
    public $user_ids = null;

    #[Validate('required|in:Task,Milestone,Meet,Reminder')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    #[Validate('nullable|array')]
    public $checklist = [];

    #[Validate('nullable|exists:tasks,id')]
    public $parent_task_id = null;

    public bool $is_trashed = false;

    public function setTask(Task $task)
    {
        $this->task = $task;
        $this->task_id = $task->id;
        $this->is_trashed = $task->trashed();
        
        // Load existing task data
        $this->title = $task->title;
        $this->type = $task->type;
        $this->project_id = $task->project_id;
        $this->vendor_id = $task->vendor_id;
        $this->user_ids = $task->user_ids ?? [];
        $this->notes = $task->notes;
        
        // Convert checklist from stdClass to array if needed
        $checklist = $task->options->checklist ?? [];
        if (!empty($checklist) && is_object($checklist)) {
            $checklist = json_decode(json_encode($checklist), true);
        } elseif (is_object($checklist) && $checklist instanceof \stdClass) {
            $checklist = (array) $checklist;
        } elseif (!empty($checklist) && !is_array($checklist)) {
            // Handle case where it's an array of objects
            $checklist = array_map(function($item) {
                return is_object($item) ? (array) $item : $item;
            }, (array) $checklist);
        }
        $this->checklist = is_array($checklist) ? $checklist : [];
        
        $this->parent_task_id = $task->parent_task_id;
        $this->order = $task->order;

        // Set dates - extract from options if stored there, otherwise try to recreate from start/end
        if (isset($task->options->dates) && is_array($task->options->dates)) {
            $this->dates = $task->options->dates;
            sort($this->dates);
        } elseif ($task->start_date && $task->end_date) {
            // Legacy: try to recreate dates array from start/end and weekend flags
            $dates = [];
            $current = $task->start_date->copy();
            $saturday = $task->options->saturday ?? false;
            $sunday = $task->options->sunday ?? false;
            
            while ($current->lte($task->end_date)) {
                if ((!$current->isSaturday() && !$current->isSunday()) ||
                    ($current->isSaturday() && $saturday) ||
                    ($current->isSunday() && $sunday)) {
                    $dates[] = $current->format('Y-m-d');
                }
                $current->addDay();
            }
            $this->dates = $dates;
        }

        // Load time settings from options
        $timeSettings = $task->options->time_settings ?? [];

        if (!empty($timeSettings) && is_object($timeSettings)) {
            $timeSettings = json_decode(json_encode($timeSettings), true);
        } elseif (!empty($timeSettings) && is_array($timeSettings)) {
            $timeSettings = json_decode(json_encode($timeSettings), true);
        }

        $this->time_settings = is_array($timeSettings) ? $timeSettings : [];

        // Load dependencies without eager loading users
        $this->refreshTaskWithDependencies($task->id);
    }

    public function update()
    {
        $this->validate();

        // Calculate start and end dates from selected dates array
        $startDate = null;
        $endDate = null;
        
        if (!empty($this->dates)) {
            sort($this->dates); // Ensure dates are in order
            $startDate = $this->dates[0];
            $endDate = end($this->dates);
        }

        if ($startDate && $endDate && $this->task->wouldOverlapWithSiblings($startDate, $endDate)) {
            $this->addError('dates', 'This task would overlap with a sibling task.');
            return false;
        }

        // Prepare options array - preserve existing options and update dates
        $options = (array) ($this->task->options ?? []);
        $options['dates'] = $this->dates;
        $options['checklist'] = $this->checklist;
        $options['time_settings'] = $this->time_settings;

        $this->task->update([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => collect($this->user_ids)->map(fn ($id) => (string) $id)->values()->all(),
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => $this->order ?? $this->task->order ?? 0, // Preserve existing order or default to 0
            'options' => $options,
            'parent_task_id' => $this->parent_task_id,
        ]);

        return $this->task;
    }

    public function store()
    {
        $this->validate();

        // Calculate start and end dates from selected dates array
        $startDate = null;
        $endDate = null;
        
        if (!empty($this->dates)) {
            sort($this->dates); // Ensure dates are in order
            $startDate = $this->dates[0];
            $endDate = end($this->dates);
        }

        if ($startDate && $endDate && $this->parent_task_id) {
            $tempTask = new Task([
                'id' => 0,
                'project_id' => $this->project_id,
                'parent_task_id' => $this->parent_task_id,
            ]);

            if ($tempTask->wouldOverlapWithSiblings($startDate, $endDate)) {
                $this->addError('dates', 'This task would overlap with a sibling task.');
                return false;
            }
        }

        // Prevent duplicate creation (double-click / double-submit)
        $recentDuplicate = Task::withoutGlobalScopes()
            ->where('project_id', $this->project_id)
            ->where('title', $this->title)
            ->where('start_date', $startDate)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->first();

        if ($recentDuplicate) {
            return $recentDuplicate;
        }

        // Store dates array in options
        $options = [
            'dates' => $this->dates,
            'checklist' => $this->checklist,
            'time_settings' => $this->time_settings,
        ];

        $task = Task::create([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $this->project_id,
            'vendor_id' => $this->vendor_id,
            'type' => $this->type,
            'user_ids' => collect($this->user_ids)->map(fn ($id) => (string) $id)->values()->all(),
            'title' => $this->title,
            'notes' => $this->notes,
            'order' => 0,
            'options' => $options,
            'parent_task_id' => $this->parent_task_id,
        ]);

        return $task;
    }

    /**
     * Refresh task with properly loaded dependencies
     */
    public function refreshTaskWithDependencies($taskId)
    {
        // Load the task with all necessary relations in a single query
        $this->task = Task::with([
            'predecessorDependencies.predecessor.vendor',
            'successorDependencies.successor.vendor',
        ])->withCount(['predecessorDependencies', 'successorDependencies'])
        ->find($taskId);
    }
}
