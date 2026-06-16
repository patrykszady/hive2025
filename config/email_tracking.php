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
    // (b) the actual From address stored on the 'sent' row (from_email), and
    // (c) internal/staff domains that should be excluded from tracking.
    'mailtrap_filter_sender_opens' => env('MAILTRAP_FILTER_SENDER_OPENS', true),

    // Comma-separated list of internal/staff email domains to ignore for open tracking.
    // Opens from these domains will be treated as "sender" opens and ignored.
    // Env var can add extra domains: EMAIL_TRACKING_INTERNAL_DOMAINS="extra.com,other.com"
    'internal_domains' => array_values(array_unique(array_filter(
        array_merge(
            ['gs.construction', 'hive.contractors'],
            array_map('trim', explode(',', (string) env('EMAIL_TRACKING_INTERNAL_DOMAINS', ''))),
        ),
        static fn (string $value): bool => $value !== ''
    ))),

    // Specific recipient addresses that should be excluded from tracking records.
    // Use EMAIL_TRACKING_EXCLUDED_RECIPIENTS to add more addresses (comma-separated).
    'excluded_recipients' => array_values(array_unique(array_filter(
        array_merge(
            ['crew@gs.construction'],
            array_map('trim', explode(',', (string) env('EMAIL_TRACKING_EXCLUDED_RECIPIENTS', ''))),
        ),
        static fn (string $value): bool => $value !== ''
    ))),

    // Internal/staff IP networks (CIDR ranges) whose opens/clicks should be treated as
    // sender opens, regardless of which recipient the event is attributed to. This catches
    // a sender viewing their own "sent" copy (the recipient's pixel still fires, but the
    // request comes from the staff network). IPv6 ranges should typically be /64 because
    // residential ISPs rotate the host portion while keeping the /64 stable per customer.
    // Set EMAIL_TRACKING_INTERNAL_IP_NETWORKS to add more (comma-separated CIDRs).
    'internal_ip_networks' => array_values(array_unique(array_filter(
        array_map('trim', explode(',', (string) env('EMAIL_TRACKING_INTERNAL_IP_NETWORKS', ''))),
        static fn (string $value): bool => $value !== ''
    ))),

    // When true, opens/clicks whose IP shares the /64 (IPv6) or exact IPv4 of a known
    // sender_ip recorded on a 'sent' event are treated as sender opens and ignored.
    'filter_sender_ip_opens' => env('EMAIL_TRACKING_FILTER_SENDER_IP_OPENS', true),

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
