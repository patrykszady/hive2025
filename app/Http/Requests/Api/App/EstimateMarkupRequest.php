<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET .../markup/preview and POST .../markup — mirrors
 * App\Livewire\Estimates\EstimateMarkup's own `markupPercent` validation
 * exactly ('required|numeric'); the percent's actual range and the
 * signed-estimate refusal are enforced by
 * App\Services\Estimates\EstimateMarkupApplier::assertAllowed(), the same
 * check both surfaces share. Real authorization ('update' on the estimate)
 * happens in the controller.
 */
class EstimateMarkupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percent' => ['required', 'numeric'],
            // Omitted (or null) means every section — mirrors
            // EstimateMarkup::$allSections.
            'section_id' => ['nullable', 'integer'],
        ];
    }
}
