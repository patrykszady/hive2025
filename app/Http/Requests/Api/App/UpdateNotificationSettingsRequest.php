<?php

namespace App\Http\Requests\Api\App;

use App\Services\Users\NotificationTimeNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * App\Livewire\Users\UserNotificationSettings::save(). The web accepts
 * free-text times like "7:00 AM" and normalizes them (normalizeRealtimeTime())
 * before the date_format:H:i rule ever runs — this does the same via the
 * shared App\Services\Users\NotificationTimeNormalizer, so the app's time
 * picker (or any other H:i-or-12-hour caller) is accepted identically.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['realtime_start', 'realtime_end'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => NotificationTimeNormalizer::normalize((string) $this->input($field))]);
            }
        }
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
