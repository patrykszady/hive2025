<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Models\User;
use App\Models\Task;
use App\Services\Tasks\TaskWriter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Form;

class TaskForm extends Form
{
    public ?Task $task = null;

    /**
     * Set only by setTask() (from a task the caller already checked is
     * visible). Locked so the browser cannot point removeTask()/restoreTask()
     * at an arbitrary task id when $task itself is not hydrated.
     */
    #[Locked]
    public ?int $task_id = null;

    #[Validate('required')]
    public $title = null;

    #[Validate('array')]
    public $dates = [];

    #[Validate('nullable|array')]
    public $time_settings = [];

    /**
     * Mirror start_time to end_time on the same date when start_time changes.
     * Each date is independent — times never propagate across dates.
     * Fires for any nested update on time_settings via wire:model.live.
     *
     * @param  mixed  $value
     */
    public function updatedTimeSettings($value, ?string $key = null): void
    {
        if (! is_string($key)) {
            return;
        }

        if (! str_ends_with($key, '.start_time')) {
            return;
        }

        [$date, $field] = explode('.', $key, 2);

        if ($date === '' || ! isset($this->time_settings[$date])) {
            return;
        }

        if (is_string($value) && trim($value) !== '') {
            $this->time_settings[$date]['end_time'] = $value;
        }
    }

    #[Validate('required')]
    public $project_id = null;

    #[Validate('nullable')]
    public $order = null;

    #[Validate('nullable|exists:vendors,id')]
    public $vendor_id = null;

    #[Validate('nullable|array')]
    public $user_ids = null;

    #[Validate('required|in:Task,Milestone,Meet,Reminder')]
    public $type = 'Task';

    #[Validate('nullable')]
    public $notes = null;

    #[Validate('nullable|array')]
    public $checklist = [];

    /** The checklist exactly as this form loaded it — the base for merging. */
    public $checklistOriginal = [];

    #[Validate('nullable|in:virtual,in_person,phone')]
    public $meeting_location_type = 'in_person';

    #[Validate('nullable|array')]
    public $meeting_participants = [];

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
        // Normalized (every item gets a stable uid) so a save can be merged
        // against concurrent card-side ticks rather than overwriting them.
        $this->checklist = \App\Models\Task::normalizeChecklist($checklist);
        $this->checklistOriginal = $this->checklist;
        
        $this->parent_task_id = $task->parent_task_id;
        $this->order = $task->order;

        // Load meeting options
        $this->meeting_location_type = $task->options->meeting_location_type ?? 'in_person';
        $meetingParticipants = $task->options->meeting_participants ?? [];
        if (is_object($meetingParticipants)) {
            $meetingParticipants = (array) $meetingParticipants;
        }
        $this->meeting_participants = array_values(array_filter((array) $meetingParticipants));

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

    /**
     * The duplicate-submit guard, sibling-overlap check and options
     * assembly — the actual record-writing logic — live in
     * App\Services\Tasks\TaskWriter so the Hive Contractors app's task
     * screens write identical rows. This form's own job stays the same:
     * validate(), then the scoped-field check below (left here, not moved,
     * so an invalid project/parent/user still shows as an inline form error
     * exactly as before rather than a thrown exception changing this
     * component's control flow).
     */
    public function update()
    {
        $this->validate();

        if (! $this->assertScopedFieldsAreValid()) {
            return false;
        }

        return app(\App\Services\Tasks\TaskWriter::class)->update($this->task, [
            'title' => $this->title,
            'project_id' => $this->project_id,
            'dates' => $this->dates,
            'time_settings' => $this->time_settings,
            'vendor_id' => $this->vendor_id,
            'user_ids' => $this->user_ids,
            'type' => $this->type,
            'notes' => $this->notes,
            'order' => $this->order,
            'meeting_location_type' => $this->meeting_location_type,
            'meeting_participants' => $this->meeting_participants,
            'parent_task_id' => $this->parent_task_id,
            'checklist' => $this->checklist,
            'checklist_original' => $this->checklistOriginal,
        ]);
    }

    public function store()
    {
        $this->validate();

        if (! $this->assertScopedFieldsAreValid()) {
            return false;
        }

        return app(\App\Services\Tasks\TaskWriter::class)->store([
            'title' => $this->title,
            'project_id' => $this->project_id,
            'dates' => $this->dates,
            'time_settings' => $this->time_settings,
            'vendor_id' => $this->vendor_id,
            'user_ids' => $this->user_ids,
            'type' => $this->type,
            'notes' => $this->notes,
            'meeting_location_type' => $this->meeting_location_type,
            'meeting_participants' => $this->meeting_participants,
            'parent_task_id' => $this->parent_task_id,
            'checklist' => $this->checklist,
        ]);
    }

    /**
     * project_id, parent_task_id and user_ids are plain public properties,
     * fully client-writable, and their own #[Validate] rules only check
     * shape (array) or bare row existence — none of them check that the row
     * belongs to this tenant. Without this, store()/update() would create or
     * move a task onto another company's project, hang a parent/child link
     * across projects, or assign internal team members who don't even work
     * here. vendor_id is deliberately left to the plain exists:vendors,id
     * rule above: assigning a task to any vendor in the shared directory
     * (not just an already-related sub) is how a new sub relationship starts.
     */
    protected function assertScopedFieldsAreValid(): bool
    {
        if (! Project::query()->whereKey($this->project_id)->exists()) {
            $this->addError('project_id', 'The selected project is invalid.');

            return false;
        }

        if ($this->parent_task_id) {
            $parent = Task::visibleTo((int) $this->parent_task_id);

            if (! $parent || (int) $parent->project_id !== (int) $this->project_id) {
                $this->addError('parent_task_id', 'The selected parent task is invalid.');

                return false;
            }
        }

        if (! empty($this->user_ids)) {
            $vendor = auth()->user()?->vendor;
            $employedUserIds = $vendor ? $vendor->users()->pluck('users.id')->all() : [];
            $requestedUserIds = array_map('intval', $this->user_ids);

            if (array_diff($requestedUserIds, array_map('intval', $employedUserIds)) !== []) {
                $this->addError('user_ids', 'One or more selected team members are invalid.');

                return false;
            }
        }

        return true;
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
