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
