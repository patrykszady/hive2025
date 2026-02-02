<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'ocr_space' => [
        'api_key' => env('OCR_SPACE_API'),
        'endpoint' => env('OCR_SPACE_ENDPOINT', 'https://api.ocr.space/parse/image'),
    ],

    'sms' => [
        // Supported: 'twilio', 'telnyx'
        'provider' => env('SMS_PROVIDER', 'telnyx'),
    ],

    'telnyx' => [
        'api_key' => env('TELNYX_API_KEY'),
        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
        'from' => env('TELNYX_FROM'),
        'dev_to' => env('TELNYX_DEV_TO'),
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

    'two_captcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
