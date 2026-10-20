<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/statuses/{status} — mirrors
 * App\Livewire\ProjectStatus\StatusCreate::updateStatus()'s own validate()
 * call exactly (no Rule::in on the code — the web doesn't restrict it to
 * the selectable list here either).
 */
class UpdateProjectStatusHistoryRequest extends FormRequest
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
            'status_code' => ['required'],
            'start_date' => ['required', 'date'],
        ];
    }
}
