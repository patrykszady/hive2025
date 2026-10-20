<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/propose-date — mirrors
 * App\Livewire\Leads\LeadCreate::updatedProposeDate() via
 * App\Services\Leads\LeadReplyComposer::proposeDate(): office-side
 * scheduling, offering any weekday rather than only the homeowner's slots.
 */
class ProposeLeadConsultDateRequest extends FormRequest
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
            'date' => ['required', 'string'],
            'availability' => ['array'],
            'availability.*.date' => ['nullable', 'string'],
            'availability.*.time' => ['nullable', 'string'],
            'availability.*.office_proposed' => ['nullable', 'boolean'],
        ];
    }
}
