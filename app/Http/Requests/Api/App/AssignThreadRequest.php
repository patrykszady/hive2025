<?php

namespace App\Http\Requests\Api\App;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignThreadRequest extends FormRequest
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
     * client_id deliberately bypasses ClientScope (same closure as
     * StartThreadRequest and the identical one SmsConversation::assignClient()
     * used to validate) — see App\Services\Sms\ThreadAdminActions::assign(),
     * which re-checks this anyway. vendor_id stays a plain exists:vendors,id
     * rule: assigning a thread to a vendor not yet related to this tenant is
     * how a new sub relationship starts.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'in:client,vendor'],
            'client_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value && ! Client::query()->withoutGlobalScopes()->whereKey($value)->exists()) {
                    $fail('The selected client is invalid.');
                }
            }],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
        ];
    }
}
