<?php

return [
    'sms' => [
        // Supported: 'twilio', 'telnyx'
        'provider' => env('SMS_PROVIDER', 'twilio'),
    ],

    'mailtrap-sdk' => [
        'host' => env('MAILTRAP_HOST', 'send.api.mailtrap.io'),
        'apiKey' => env('MAILTRAP_API_KEY'),
        'inboxId' => env('MAILTRAP_INBOX_ID'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'), // Your Twilio phone number
        'dev_to' => env('TWILIO_DEV_TO', '+12249993880'),
    ],

    'telnyx' => [
        // API Authentication
        'api_key' => env('TELNYX_API_KEY', ''),

        // Messaging
        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID', ''),
        'from' => env('TELNYX_FROM', ''), // Your Telnyx SMS-enabled phone number (E.164)
        'dev_to' => env('TELNYX_DEV_TO'), // Override recipient in dev/local

        // Voice
        'connection_id' => env('TELNYX_CONNECTION_ID', ''), // Voice API Application ID (connection_id)
        'voice_forward_to' => env('TELNYX_VOICE_FORWARD_TO'), // Comma-separated phone numbers to forward inbound calls to

        // Webhook verification (optional but recommended)
        'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'),

        // Compliance auto-replies (STOP / START / HELP)
        // These should be short (carrier-friendly) and can be overridden per environment.
        'auto_replies' => [
            'stop' => env('TELNYX_AUTO_REPLY_STOP'),
            'start' => env('TELNYX_AUTO_REPLY_START'),
            'help' => env('TELNYX_AUTO_REPLY_HELP'),
        ],
    ],

    'microsoft_teams' => [
        // Incoming Webhook URL for a Teams channel (one-way mirroring of SMS events).
        'sms_webhook_url' => env('TEAMS_SMS_WEBHOOK_URL'),

        // Shared secret used by Power Automate (or any integration) to call back into Hive.
        'inbound_token' => env('TEAMS_INBOUND_TOKEN'),
    ],

    'two_captcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],
];
