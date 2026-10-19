<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/duplicate — mirrors
 * App\Livewire\Estimates\EstimateDuplicate::save(). Real authorization
 * (Estimate::class 'create' against the target project) happens in the
 * controller.
 */
class DuplicateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'cost_mode' => ['nullable', 'in:exact,current'],
            'include_allowances' => ['nullable', 'boolean'],
        ];
    }
}
