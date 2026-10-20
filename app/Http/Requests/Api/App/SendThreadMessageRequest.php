<?php

namespace App\Http\Requests\Api\App;

use App\Livewire\Sms\SmsConversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendThreadMessageRequest extends FormRequest
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
     * media.* reuses SmsConversation::attachmentValidationRule() — the exact
     * rule the web composer validates its single attachment against — so an
     * app upload is accepted or rejected the same way a web one is.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:media', 'string', 'max:1600'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => SmsConversation::attachmentValidationRule(),
            // The web composer's "Schedule send" presets (America/Chicago).
            // 'schedule_only' is the web's "Schedule Only (manual send)" —
            // saved as a draft with no send time, sent later by hand
            // (ThreadMessageController::sendNow()).
            'send_at' => ['nullable', 'string', 'in:1hr,2hr,schedule_only,tomorrow_8am,tomorrow_12pm'],
        ];
    }
}
