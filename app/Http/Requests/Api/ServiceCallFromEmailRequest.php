<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/service-calls/from-email — an email a site's mail reader is
 * about to judge as a lead. Keyed by the sha1 of its RFC Message-ID, the
 * identity Hive's own reader uses for the same message.
 */
class ServiceCallFromEmailRequest extends FormRequest
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
            'message_key' => ['required', 'string', 'max:64'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'to' => ['nullable', 'array', 'max:50'],
            'to.*' => ['string', 'max:255'],
            'cc' => ['nullable', 'array', 'max:50'],
            'cc.*' => ['string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:20000'],
            'mailbox' => ['nullable', 'string', 'max:255'],
            'rfc_message_id' => ['nullable', 'string', 'max:998'],
            'received_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message_key.required' => 'The message key (sha1 of the Message-ID) is required.',
            'from_email.required' => 'The sender address is required.',
        ];
    }
}
