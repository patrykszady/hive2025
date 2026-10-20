<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/app/v1/addresses/autocomplete — mirrors
 * App\Traits\HandlesAddresses::updatedAddressQuery(). Any signed-in app
 * user may call this: it is reused by every form with an address field
 * (vendors, team members, and — once those tracks merge this component —
 * clients/projects/leads too), the same way HandlesAddresses is reused by
 * every web form's Livewire component.
 */
class AutocompleteAddressRequest extends FormRequest
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
            // GeoapifyService::getAutocompleteSuggestions() itself no-ops
            // under 3 characters; requiring it here avoids billing Geoapify
            // for a query that will come back empty anyway.
            'query' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
