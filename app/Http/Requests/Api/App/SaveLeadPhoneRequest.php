<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/phone — mirrors the web's phoneEntry box
 * (App\Livewire\Leads\LeadCreate::saveContactPhone()): a hand-entered phone
 * for an enquiry that arrived without one. The 10-digit + uniqueness check
 * happens in App\Services\Leads\LeadContactCompletion, same as the web, so
 * this only requires a non-empty string.
 */
class SaveLeadPhoneRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:30'],
        ];
    }
}
