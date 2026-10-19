<?php

namespace App\Http\Requests\Api\App;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            // The planner action item this task was created from
            // (actionItemToTask()'s mobile equivalent) — settled by the
            // controller, same as TaskCreate::save().
            'action_item_id' => ['nullable', 'integer'],
        ];
    }
}
