<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/ai-rules — mirrors
 * App\Livewire\Estimates\EstimateAIGenerator::addRule()'s own rule. Real
 * authorization ('update' on the estimate) happens in the controller.
 */
class StoreEstimateAiRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
