<?php

namespace App\Http\Requests\Api\App;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/tasks — mirrors App\Livewire\Forms\TaskForm's own rules
 * exactly. Task creation is open to any team member
 * (App\Policies\TaskPolicy::create — not Admin-gated, unlike Project/Bid).
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Task::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'project_id' => ['required', 'integer'],
            'dates' => ['array'],
            'dates.*' => ['date_format:Y-m-d'],
            'time_settings' => ['nullable', 'array'],
            'time_settings.*.use_time' => ['nullable', 'boolean'],
            'time_settings.*.start_time' => ['nullable', 'string'],
            'time_settings.*.end_time' => ['nullable', 'string'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'type' => ['required', 'in:Task,Milestone,Meet,Reminder'],
            'notes' => ['nullable', 'string'],
            'meeting_location_type' => ['nullable', 'in:virtual,in_person,phone'],
            'meeting_participants' => ['nullable', 'array'],
            'meeting_participants.*' => ['string'],
            'parent_task_id' => ['nullable', 'exists:tasks,id'],
            // A brand-new task has nothing stored to merge against —
            // App\Services\Tasks\TaskWriter::store() writes this list as-is,
            // same as TaskForm::store().
            'checklist' => ['nullable', 'array'],
            'checklist.*.uid' => ['nullable', 'string'],
            'checklist.*.text' => ['required', 'string'],
            'checklist.*.completed' => ['nullable', 'boolean'],
            // The planner action item this task was created from
            // (actionItemToTask()'s mobile equivalent) — settled by the
            // controller, same as TaskCreate::save().
            'action_item_id' => ['nullable', 'integer'],
            // Predecessors chosen before this task existed — mirrors
            // TaskCreate::addDependency()'s not-yet-saved branch
            // ($pendingDependencies), flushed by the controller once the
            // task has an id (TaskCreate::save()'s own flush). Each is
            // independently validated (self/circular/visibility) there via
            // App\Services\Tasks\TaskDependencyWriter::add() — one that no
            // longer qualifies is skipped rather than failing the create.
            'pending_dependencies' => ['nullable', 'array'],
            'pending_dependencies.*.predecessor_task_id' => ['required', 'integer'],
            'pending_dependencies.*.type' => ['required', Rule::in(['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'])],
            'pending_dependencies.*.lag_days' => ['nullable', 'integer'],
        ];
    }
}
