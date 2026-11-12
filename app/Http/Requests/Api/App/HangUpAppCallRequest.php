<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HangUpAppCallRequest extends FormRequest
{
    /**
     * Why the app ended the call: the person pressed End (local-hangup),
     * pressed it before the call was placed (cancelled), or the app lost its
     * connection to the call (dropped). Kept for the call's log line only.
     */
    public const REASONS = ['local-hangup', 'cancelled', 'dropped'];

    /**
     * Determine if the user is authorized to make this request. Whether the
     * person placed this call is checked in VoiceController::hangup(), which
     * answers 404 for a call that does not exist.
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
            'reason' => ['nullable', 'string', 'in:'.implode(',', self::REASONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.in' => 'The reason must be one of: '.implode(', ', self::REASONS).'.',
        ];
    }
}
