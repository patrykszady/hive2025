<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates — mirrors App\Livewire\Estimates\EstimateCreate:
 * a blank estimate is created for a project, with its first section added by
 * the caller (see EstimateController::store). Real authorization
 * (Estimate::class 'create', which needs the resolved Project) happens in
 * the controller, same pattern as StorePaymentRequest.
 */
class StoreEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
        ];
    }
}
