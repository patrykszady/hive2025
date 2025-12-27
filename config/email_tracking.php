<?php

return [
    // Tracking is Mailtrap-only.
    'provider' => 'mailtrap',

    // Which Laravel mailer to use for tracked emails when provider=mailtrap.
    // This should be the API-based mailer (mailtrap-sdk), not SMTP.
    'mailtrap_mailer' => env('MAILTRAP_TRACKING_MAILER', 'mailtrap-sdk'),

    // Shared secret for the Mailtrap webhook endpoint (validated against the URL token).
    'mailtrap_webhook_token' => 'mailtrap-webhook-token',

    // Filter out likely bot activity from Mailtrap webhooks (e.g. security scanners).
    'mailtrap_filter_bots' => env('MAILTRAP_FILTER_BOTS', true),

    // Filter out opens/clicks that are likely the sender (or internal staff) viewing their own copy.
    // This uses (a) per-message sender_email stored on the matching 'sent' row metadata when available,
    // and (b) the actual From address stored on the 'sent' row (from_email).
    'mailtrap_filter_sender_opens' => env('MAILTRAP_FILTER_SENDER_OPENS', true),

    // If an opened/clicked event happens "too soon" after our tracked 'sent' event (same tracking_id),
    // treat it as automated prefetch/scanning.
    'mailtrap_bot_open_within_seconds' => (int) env('MAILTRAP_BOT_OPEN_WITHIN_SECONDS', 15),

    // Comma-separated substrings to detect bot/scanner user agents.
    // Set MAILTRAP_BOT_UA_SUBSTRINGS to override.
    'mailtrap_bot_ua_substrings' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('MAILTRAP_BOT_UA_SUBSTRINGS', ''))),
        static fn (string $value): bool => $value !== ''
    )) ?: [
        'googlebot',
        'bingbot',
        'duckduckbot',
        'yandexbot',
        'baiduspider',
        'facebookexternalhit',
        'twitterbot',
        'slackbot',
        'discordbot',
        'telegrambot',
        'whatsapp',
        'linkpreview',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'python-requests',
        'curl/',
        'wget/',
        'postmanruntime',
        'insomnia',
        'proofpoint',
        'mimecast',
        'barracuda',
        'sophos',
        'trend micro',
        'forcepoint',
        'symantec',
        'spamtitan',
        'avanan',
        'ironscales',
        'safelinks',
        'microsoft defender',
    ],
];
