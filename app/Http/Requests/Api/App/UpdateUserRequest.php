<?php

namespace App\Http\Requests\Api\App;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Edit User" — App\Livewire\Forms\UserForm::update(). Cell phone is
 * deliberately not here: the web's edit form disables that field once the
 * person already exists, and the controller never writes it either.
 * Resource-level authorization (UserPolicy::update — self, or an admin of
 * the same vendor / a shared client) happens in the controller.
 */
class UpdateUserRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'min:2'],
            'last_name' => ['required', 'string', 'min:2'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'preferred_language' => ['required', 'string', Rule::in(User::PREFERRED_LANGUAGES)],
            'email' => [
                'required',
                'email',
                'min:4',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'position' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already in use by another user.',
        ];
    }
}
