<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/phone-system — the same rules
 * App\Livewire\Vendors\PhoneSystemOptions' rules() enforce. authorize()
 * also re-checks primary_vendor_id === 1, mirroring that component's
 * mount()'s abort_unless (the phone system belongs to the company whose
 * Telnyx numbers the line uses).
 */
class UpdatePhoneSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (int) $user->primary_vendor_id === 1
            && (bool) $user->can('viewOptions', Vendor::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'call_recipients' => ['array'],
            'call_recipients.*' => ['integer', 'exists:users,id'],
            'vendor_call_order_enabled' => ['required', 'boolean'],
            'vendor_call_recipients' => ['array'],
            'vendor_call_recipients.*' => ['integer', 'exists:users,id'],
            'vendor_call_ring_mode' => ['required', 'in:one_by_one,all'],
            'call_welcome_enabled' => ['required', 'boolean'],
            'voicemail_enabled' => ['required', 'boolean'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'welcome_message_unknown' => ['nullable', 'string', 'max:500'],
            'screening_message' => ['nullable', 'string', 'max:500'],
            'screening_enabled' => ['required', 'boolean'],
            'missed_call_text' => ['nullable', 'string', 'max:320'],
            'inbound_callback_text' => ['nullable', 'string', 'max:320'],
            'voicemail_message' => ['nullable', 'string', 'max:500'],
            'voicemail_message_unknown' => ['nullable', 'string', 'max:500'],
            'ivr_press1_message' => ['nullable', 'string', 'max:500'],
            'ivr_press2_message' => ['nullable', 'string', 'max:500'],
            'voicemail_greeting' => ['nullable', 'string', 'max:500'],
            'spam_message' => ['nullable', 'string', 'max:500'],
            'after_hours_message' => ['nullable', 'string', 'max:500'],
            'recording_disclosure_message' => ['nullable', 'string', 'max:300'],
        ];
    }
}
