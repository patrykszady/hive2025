<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/sections/{section}/ai-draft —
 * mirrors App\Livewire\Estimates\EstimateAIGenerator's inquiry rule. Floor
 * plan uploads are left web-only (see EstimateAiController). Real
 * authorization ('update' on the estimate) happens in the controller.
 */
class GenerateEstimateAiDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inquiry' => ['required', 'string', 'min:10'],
        ];
    }
}
