<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step one of "New Client": the contact person's phone number —
 * App\Livewire\Users\UserCreate::user_cell_find(). Resource-level
 * authorization (admin-only, same as the "New Client" button's
 * @can('create', Client::class)) happens in the controller.
 */
class LookupClientPersonRequest extends FormRequest
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
        ];
    }
}
