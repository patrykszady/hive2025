<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/app/v1/estimates/{estimate}/line-items/{lineItem}/move —
 * mirrors App\Livewire\Estimates\EstimateShow::sort_line_item()'s own
 * input: the target 0-indexed position in the destination section's
 * visible (ordered) list, and an optional section_id for a cross-section
 * move (omitted/null keeps it in its current section). Real authorization
 * ('update' on the estimate) happens in the controller.
 */
class MoveEstimateLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position' => ['required', 'integer', 'min:0'],
            'section_id' => ['nullable', 'integer'],
        ];
    }
}
