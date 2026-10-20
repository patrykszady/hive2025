<?php

namespace App\Http\Requests;

use App\Rules\AppReturnToUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /company-email/login — starting the Nylas Connect flow. The route
 * itself is gated by the `auth` middleware; the only thing this validates
 * is the optional `return_to`, the Hive Contractors app's deep link back to
 * itself once the OAuth round trip (and CompanyEmailController::
 * nylasAuthResponse()) finishes. Desktop callers (and the app's own popup
 * mode) never send it, so leaving it off behaves exactly as before.
 */
class NylasLoginRequest extends FormRequest
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
            'return_to' => ['nullable', 'string', 'max:2048', new AppReturnToUrl()],
        ];
    }
}
