<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/tasks/{task} — same shape and rules as
 * StoreTaskRequest (mirrors TaskForm::update()'s own #[Validate] rules);
 * authorization is checked against the specific task in the controller
 * (App\Policies\TaskPolicy::update — company-level, not Admin-gated).
 */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'order' => ['nullable', 'integer'],
            'meeting_location_type' => ['nullable', 'in:virtual,in_person,phone'],
            'meeting_participants' => ['nullable', 'array'],
            'meeting_participants.*' => ['string'],
            'parent_task_id' => ['nullable', 'exists:tasks,id'],
            // The three-way checklist merge (Task::mergeChecklist(), via
            // App\Services\Tasks\TaskWriter::update()) needs BOTH of these:
            // `checklist_original` is exactly what GET tasks/{id} last
            // returned (before this screen's own edits), so a box ticked
            // elsewhere while this screen was open isn't reverted.
            'checklist' => ['nullable', 'array'],
            'checklist.*.uid' => ['nullable', 'string'],
            'checklist.*.text' => ['required', 'string'],
            'checklist.*.completed' => ['nullable', 'boolean'],
            'checklist_original' => ['nullable', 'array'],
            'checklist_original.*.uid' => ['nullable', 'string'],
            'checklist_original.*.text' => ['nullable', 'string'],
            'checklist_original.*.completed' => ['nullable', 'boolean'],
        ];
    }
}
