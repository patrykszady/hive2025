<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project} — mirrors the web's Update Project
 * modal (App\Livewire\Projects\ProjectCreate::editProject() +
 * App\Livewire\Forms\ProjectForm::update()): client_id and project_name
 * required exactly like create, but the address is always given directly
 * here (no project_existing_address selector — that's a create-only
 * concept for copying a client's past address). Validation only — same as
 * UpdateProjectStatusRequest, the {project} route param here is a plain
 * int (not a bound model), so the Admin-only check
 * (App\Policies\ProjectPolicy::update) happens in the controller once the
 * project is resolved (visibleProject() + $this->authorize()).
 */
class UpdateProjectRequest extends FormRequest
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
            'client_id' => ['required', 'integer'],
            'project_name' => ['required', 'string', 'min:3'],
            'address' => ['required', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_name.min' => 'Project name must be at least 3 characters.',
        ];
    }
}
