<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/address-candidate — mirrors
 * App\Livewire\Leads\LeadCreate::selectAddressCandidate(): pick one of the
 * addresses CompleteLeadAddress found for this lead's street, by its index
 * in the stored list (see LeadResource's `address_candidates`).
 */
class SelectLeadAddressCandidateRequest extends FormRequest
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
            'index' => ['required', 'integer', 'min:0'],
        ];
    }
}
