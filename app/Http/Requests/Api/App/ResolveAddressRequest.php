<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/app/v1/addresses/resolve — mirrors
 * App\Traits\HandlesAddresses::selectSuggestion(): turns a suggestion's
 * place_id (from AutocompleteAddressRequest's results) into its full
 * address fields.
 */
class ResolveAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'place_id' => ['required', 'string', 'max:500'],
        ];
    }
}
