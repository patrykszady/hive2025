<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/app/v1/estimates/{estimate}/sections/{section}/move — mirrors
 * App\Livewire\Estimates\EstimateShow::sort_sections()'s own input: the
 * target 0-indexed position in the visible (ordered) list of sections.
 * Real authorization ('update' on the estimate) happens in the controller.
 */
class MoveEstimateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
