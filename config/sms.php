<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Business Hours Configuration
    |--------------------------------------------------------------------------
    |
    | These settings define the time window during which SMS notifications
    | can be sent. All three SMS types (client, vendor, team) share these
    | settings for consistency.
    |
    */

    'business_hours' => [
        // Default timezone for business hours calculations
        'timezone' => env('SMS_BUSINESS_TIMEZONE', 'America/Chicago'),

        // Start time (24-hour format)
        'start_hour' => (int) env('SMS_BUSINESS_START_HOUR', 7),
        'start_minute' => (int) env('SMS_BUSINESS_START_MINUTE', 0),

        // End time (24-hour format)
        'end_hour' => (int) env('SMS_BUSINESS_END_HOUR', 20),
        'end_minute' => (int) env('SMS_BUSINESS_END_MINUTE', 0),

        // Whether to skip weekends
        'skip_weekends' => env('SMS_SKIP_WEEKENDS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound Translation
    |--------------------------------------------------------------------------
    |
    | Whether a text typed in Hive is translated into the recipient's
    | preferred language before it goes out (conversation composer, its
    | schedule menu, edits of a scheduled text). Off since 2026-10-27:
    | messages go out as typed, in English, the way the schedule blast modal
    | already sends them ("we got rid of translating messages for now").
    | Reading stays as it was — foreign inbound text is shown in English and
    | a reader can translate one message on demand.
    |
    */

    'translate_outbound' => (bool) env('SMS_TRANSLATE_OUTBOUND', false),

    /*
    |--------------------------------------------------------------------------
    | Day-of-Change Notifications
    |--------------------------------------------------------------------------
    |
    | Settings for real-time notifications when schedules change during
    | the current day.
    |
    */

    'day_of_changes' => [
        // Delay in minutes before sending (allows batching rapid changes)
        'delay_minutes' => (int) env('SMS_CHANGE_DELAY_MINUTES', 15),

        // Throttle window - don't send more than one SMS per entity per window
        'throttle_minutes' => (int) env('SMS_THROTTLE_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Channels
    |--------------------------------------------------------------------------
    |
    | Log channel names for each SMS type.
    |
    */

    'log_channels' => [
        'client' => 'client_sms',
        'vendor' => 'vendor_sms',
        'team' => 'team_sms',
    ],
];
