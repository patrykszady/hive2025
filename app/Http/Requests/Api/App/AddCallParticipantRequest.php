<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddCallParticipantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The opaque "kind:identifier" key a participants/search result
            // carries (App\Services\Calls\ConferenceParticipantSearch) —
            // "user:12", "vendor:4", or "number:+18475550199".
            'key' => ['required', 'string', 'max:60'],
        ];
    }
}
