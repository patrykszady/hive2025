<?php

return [
    'sync' => [
        // Ordered retry limits (initial + fallbacks). Can be tuned per environment.
        'limits' => env('NYLAS_SYNC_LIMITS', '45,15,7,3'), // comma separated
        'failure_threshold' => env('NYLAS_FAILURE_THRESHOLD', 3),
        'pause_minutes' => env('NYLAS_PAUSE_MINUTES', 15),
        // Overlap seconds when advancing cursor to avoid missing edge messages.
        'cursor_overlap_seconds' => env('NYLAS_CURSOR_OVERLAP_SECONDS', 60),
    ],
    'headers' => [
        // Include full headers only when explicitly requested.
        'include_headers_default' => false,
        'user_agent' => env('NYLAS_USER_AGENT', 'HiveApp/1.0 (+support@hive.example)'),
    ],
    'rate_limit' => [
        // Fallback exponential base (ms) for 429 when Retry-After not present.
        'base_backoff_ms' => 1500,
        'max_backoff_ms' => 30000,
    ],
];
