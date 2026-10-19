<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * App\Livewire\Users\UserNotificationSettings::save(). The web also
 * accepts free-text times like "7:00 AM" and normalizes them before this
 * same rule runs; the app's own time picker always supplies H:i directly,
 * so that normalization step has no equivalent here.
 */
class UpdateNotificationSettingsRequest extends FormRequest
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
            'realtime_email' => ['boolean'],
            'morning_email' => ['boolean'],
            'morning_sms' => ['boolean'],
            'evening_email' => ['boolean'],
            'evening_sms' => ['boolean'],
            'realtime_start' => ['required', 'date_format:H:i'],
            'realtime_end' => ['required', 'date_format:H:i', 'after:realtime_start'],
        ];
    }
}
