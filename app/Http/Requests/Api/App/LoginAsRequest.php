<?php

namespace App\Http\Requests\Api\App;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/admin/login-as — the team member to view the app as.
 * Gated like the web's AdminLoginAsUser (UserPolicy::admin_login_as_user).
 */
class LoginAsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('admin_login_as_user', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Choose the team member to log in as.',
        ];
    }
}
