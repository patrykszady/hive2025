<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/estimates/{estimate}/contract-settings — mirrors the
 * signer-ids/contract-template-ids/bid-reassignment slice of
 * App\Livewire\Estimates\EstimateAccept's "Settings" modal save(), via
 * App\Services\Estimates\EstimateContractSettingsWriter. Real authorization
 * ('update' on the estimate) happens in the controller.
 */
class UpdateEstimateContractSettingsRequest extends FormRequest
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
            'required_vendor_signer_ids' => ['array'],
            'required_vendor_signer_ids.*' => ['integer'],
            'contract_template_ids' => ['array'],
            'contract_template_ids.*' => ['integer'],
            // section id (string key, JSON object) => bid id
            'section_bids' => ['array'],
            'section_bids.*' => ['integer'],
        ];
    }
}
