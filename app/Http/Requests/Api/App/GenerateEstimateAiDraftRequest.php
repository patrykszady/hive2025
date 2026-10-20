<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/sections/{section}/ai-draft —
 * mirrors App\Livewire\Estimates\EstimateAIGenerator's inquiry rule, plus
 * the floorplan upload — images only on the app (expo-image-picker has no
 * document/CSV picker in this Expo Go build); see EstimateAiController.
 * Real authorization ('update' on the estimate) happens in the controller.
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
            'floorplans' => ['nullable', 'array', 'max:5'],
            'floorplans.*' => ['file', 'image', 'max:10240'],
        ];
    }
}
