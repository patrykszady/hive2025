<?php

return [
    'sms' => [
        // Supported: 'twilio'
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

    'two_captcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],
];
