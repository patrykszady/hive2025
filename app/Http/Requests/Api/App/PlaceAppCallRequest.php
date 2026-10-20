<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PlaceAppCallRequest extends FormRequest
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
        $phoneRule = function (string $attribute, mixed $value, \Closure $fail): void {
            $digits = strlen((string) preg_replace('/\D/', '', (string) $value));
            if ($digits < 10 || $digits > 15) {
                $fail('Enter a full phone number.');
            }
        };

        return [
            'to' => ['required_without:targets', 'string', 'max:30', $phoneRule],
            // The web conversation's "Call All" (App\Livewire\Sms\
            // SmsConversation::initiateCallAll()): every thread participant
            // dialed in the same click-to-call session, instead of just one
            // target. Mutually exclusive with `to` — provide one or the other.
            'targets' => ['required_without:to', 'array', 'min:1'],
            'targets.*' => ['string', 'max:30', $phoneRule],
            // Which of the caller's phones Hive rings first: the app (default),
            // or their cell (Expo Go, before the app has its own calling).
            'ring' => ['nullable', 'string', 'in:app,cell'],
            // The web's multi-admin ring recipient choice (CallList's "New
            // Call" modal): other admin team members to ring in alongside
            // this call — App\Services\Voice\ClickToCall::start()'s
            // $recipientIds. Invalid/non-admin ids are silently dropped there.
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer'],
        ];
    }
}
