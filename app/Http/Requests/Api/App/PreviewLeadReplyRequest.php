<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/reply-preview — recomputes the composer's
 * template-filled subject/body and picker state for a selection, mirroring
 * what App\Livewire\Leads\LeadCreate's Computed properties recalculate on
 * every wire:click (insertAvailabilitySlot/selectExactTime/etc). See
 * App\Services\Leads\LeadReplyComposer::preview().
 */
class PreviewLeadReplyRequest extends FormRequest
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
            'template_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'email'],
            'subject' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'availability' => ['array'],
            'availability.*.date' => ['nullable', 'string'],
            'availability.*.time' => ['nullable', 'string'],
            'availability.*.office_proposed' => ['nullable', 'boolean'],
            'selected_availability' => ['array'],
            'selected_availability.*' => ['integer'],
            'selected_exact_time' => ['nullable', 'string'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'consult_meeting_type' => ['nullable', 'string', 'in:in_person,virtual'],
        ];
    }
}
