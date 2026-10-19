<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/estimates/{estimate}/sections/{section} — mirrors
 * App\Livewire\Estimates\EstimateShow::sectionUpdate(). Real authorization
 * ('update' on the estimate) happens in the controller.
 */
class UpdateEstimateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
