<?php

namespace App\Services\Users;

use Carbon\Carbon;

/**
 * Parses a realtime-quiet-hours time into H:i — the shared body of
 * App\Livewire\Users\UserNotificationSettings::normalizeRealtimeTime() and
 * the Hive Contractors app's notification-settings endpoint
 * (UpdateNotificationSettingsRequest), so a 12-hour time like "7:00 AM" is
 * accepted identically on both. An unparsable value is returned trimmed
 * but otherwise unchanged, same as the web — the date_format:H:i rule that
 * runs after this is what actually rejects it.
 */
class NotificationTimeNormalizer
{
    /** @var list<string> */
    private const FORMATS = [
        'H:i',
        'H:i:s',
        'g:i A',
        'g:iA',
        'g:i:s A',
        'g:i:sA',
    ];

    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        foreach (self::FORMATS as $format) {
            try {
                return Carbon::createFromFormat($format, strtoupper($trimmed))->format('H:i');
            } catch (\Exception $e) {
                continue;
            }
        }

        return $trimmed;
    }
}
