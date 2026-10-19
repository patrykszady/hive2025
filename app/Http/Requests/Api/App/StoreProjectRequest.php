<?php

namespace App\Http\Requests\Api\App;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects — mirrors App\Livewire\Forms\ProjectForm's own
 * rules exactly (client_id required, project_name required|min:3,
 * project_existing_address required), plus the address lines that only
 * matter when project_existing_address is 'NEW' (a brand new address
 * rather than a copy of the client's own address or a past project's).
 * Project creation itself is Admin-only (App\Policies\ProjectPolicy::create).
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Project::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'project_name' => ['required', 'string', 'min:3'],
            'project_existing_address' => ['required', 'string'],
            'address' => ['nullable', 'required_if:project_existing_address,NEW', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'required_if:project_existing_address,NEW', 'string', 'max:255'],
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
            'address.required_if' => 'Give an address, or choose an existing one.',
            'city.required_if' => 'Give a city, or choose an existing address.',
        ];
    }
}
