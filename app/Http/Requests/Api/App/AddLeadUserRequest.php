<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/add-user — mirrors
 * App\Livewire\Leads\LeadCreate::addUser(): a user record from the name,
 * email and phone on the lead, created and linked to it, for a lead whose
 * contact was never provisioned.
 */
class AddLeadUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('email', 'An email or phone number is needed to create a user.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Enter a name.',
        ];
    }
}
