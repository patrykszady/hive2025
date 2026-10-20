<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/sections — mirrors
 * App\Livewire\Estimates\EstimateShow::sectionAdd(). Real authorization
 * ('update' on the estimate) happens in the controller.
 */
class StoreEstimateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
