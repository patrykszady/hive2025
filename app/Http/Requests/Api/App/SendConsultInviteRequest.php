<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/send-consult-invite — mirrors
 * App\Livewire\Leads\LeadCreate::sendConsultInvite(): the "Consult"
 * template is applied server-side, so only recipients/from and the
 * composer's current availability selection are needed here.
 */
class SendConsultInviteRequest extends FormRequest
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
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'from' => ['required', 'email'],
            'availability' => ['array'],
            'availability.*.date' => ['nullable', 'string'],
            'availability.*.time' => ['nullable', 'string'],
            'availability.*.office_proposed' => ['nullable', 'boolean'],
            'selected_availability' => ['array'],
            'selected_availability.*' => ['integer'],
            'selected_exact_time' => ['nullable', 'string'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'consult_meeting_type' => ['nullable', 'string', 'in:in_person,virtual'],
            'nylas_message_id' => ['nullable', 'string'],
            'nylas_references' => ['array'],
            'nylas_references.*' => ['string'],
        ];
    }
}
