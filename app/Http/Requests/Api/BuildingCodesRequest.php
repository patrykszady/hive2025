<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/building-codes?town=Schaumburg&state=IL — the codes a served
 * town's building department has adopted, asked by a partner site
 * (gs.construction) for its town pages.
 */
class BuildingCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->vendor;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'town' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL][\pL\s.\'-]*$/u'],
            'state' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'town.required' => 'Name the town whose building codes you want.',
            'town.regex' => 'A town name is letters, spaces, periods, apostrophes and hyphens only.',
            'state.size' => 'The state is its two-letter code, like IL.',
        ];
    }
}
