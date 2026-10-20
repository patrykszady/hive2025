<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/tasks/{task}/dependencies — mirrors
 * App\Livewire\Tasks\TaskCreate::addDependency()'s own validate() call
 * (the tenant/self/circular/duplicate checks run in
 * App\Services\Tasks\TaskDependencyWriter::add(), same as the web).
 */
class StoreTaskDependencyRequest extends FormRequest
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
            'predecessor_task_id' => ['required', 'integer', 'exists:tasks,id'],
            'type' => ['required', 'in:finish_to_start,start_to_start,finish_to_finish,start_to_finish'],
            'lag_days' => ['nullable', 'integer'],
        ];
    }
}
