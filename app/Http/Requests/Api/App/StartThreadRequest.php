<?php

namespace App\Http\Requests\Api\App;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartThreadRequest extends FormRequest
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
     * exists:clients,id is deliberately not used for client_id — it is a
     * bare table check that bypasses Client's tenant-scoping global scope,
     * same as the identical closure in App\Livewire\Sms\SmsNewThread::send()
     * and App\Livewire\Sms\SmsConversation::assignClient(). vendor_id stays
     * a plain exists:vendors,id rule — same reasoning as SmsNewThread's
     * vendor recipient type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'required_without_all:phones,vendor_id', function ($attribute, $value, $fail) {
                if ($value && ! Client::query()->withoutGlobalScopes()->whereKey($value)->exists()) {
                    $fail('The selected client is invalid.');
                }
            }],
            'vendor_id' => ['nullable', 'required_without_all:phones,client_id', 'exists:vendors,id'],
            'phones' => ['nullable', 'required_without_all:client_id,vendor_id', 'array', 'min:1'],
            'phones.*' => ['string'],
            'body' => ['required', 'string', 'max:1600'],
        ];
    }
}
