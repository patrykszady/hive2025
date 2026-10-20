<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/sections/{section}/duplicate-to-estimate
 * — mirrors App\Livewire\Estimates\EstimateDuplicate::save_section(): a
 * null/absent `target_estimate_id` means "a brand new estimate" (the web's
 * estimate_id === 'new'); otherwise it must name a DIFFERENT estimate in
 * the same project, checked in the controller the same way the Livewire
 * component's save_section() throws for the same-estimate case.
 */
class DuplicateEstimateSectionToEstimateRequest extends FormRequest
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
            'target_estimate_id' => ['nullable', 'integer'],
            'cost_mode' => ['nullable', 'in:exact,current'],
            'include_allowances' => ['nullable', 'boolean'],
        ];
    }
}
