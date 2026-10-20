<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/app/v1/leads/{lead} — the lead's contact/address fields and
 * origin, mirroring App\Livewire\Leads\LeadCreate::edit() (status changes
 * are their own endpoint — see UpdateLeadStatusRequest — so this never
 * writes a status row, unlike edit()'s combined save).
 */
class UpdateLeadRequest extends FormRequest
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
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'origin' => ['nullable', 'string', 'max:255'],
        ];
    }
}
