<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/combine-items — the same rules as
 * the website's "Combine Items" window
 * (App\Livewire\Estimates\EstimateCombineLineItems::combine()).
 * Real authorization ('update' on the estimate) happens in the controller.
 */
class CombineEstimateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string', 'max:40'],
            'section_name' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keys.required' => 'Tick at least one item to combine.',
            'section_name.required' => 'Name the section the combined items go in.',
            'section_name.max' => 'A section name can be 60 characters at most.',
        ];
    }
}
