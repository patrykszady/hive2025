<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Add Member" on the team — the vendor branch of
 * App\Livewire\Users\UserCreate::save() for an existing vendor id.
 * Resource-level authorization (UserPolicy::create_team_member) happens in
 * the controller.
 */
class StoreTeamMemberRequest extends FormRequest
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
            'cell_phone' => ['required', 'digits:10'],
            'first_name' => ['nullable', 'string', 'min:2'],
            'last_name' => ['nullable', 'string', 'min:2'],
            'email' => ['nullable', 'email', 'min:4'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in([1, 2])],
            'hourly_rate' => ['required_with:role', 'numeric', 'min:0'],
        ];
    }
}
