<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Add Member" on a client — the client branch of
 * App\Livewire\Users\UserCreate::save() for an existing client id.
 * Resource-level authorization (UserPolicy::create_client_member) happens
 * in the controller.
 */
class AddClientPersonRequest extends FormRequest
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
        ];
    }
}
