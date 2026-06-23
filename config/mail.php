<?php

return [

    'default' => env('MAIL_MAILER', 'mailtrap-sdk'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS'),
        'name' => env('MAIL_FROM_NAME'),
    ],

    // Where to redirect all outgoing mail in local/development/testing.
    'dev_email' => env('MAIL_DEV_TO_ADDRESS', env('MAIL_DEV_EMAIL')),

    // Addresses that should never receive outgoing mail. Any matching recipient is
    // stripped from To/Cc/Bcc before sending; if nothing remains, the send is cancelled.
    // Add more via MAIL_SUPPRESSED_RECIPIENTS (comma-separated).
    'suppressed_recipients' => array_values(array_unique(array_filter(
        array_map(
            static fn (string $value): string => strtolower(trim($value)),
            array_merge(
                ['support@hive.contractors'],
                explode(',', (string) env('MAIL_SUPPRESSED_RECIPIENTS', '')),
            )
        ),
        static fn (string $value): bool => $value !== ''
    ))),

    'mailers' => [
        'nylas' => [
            'transport' => 'nylas',
            'grant_id' => null, // Will be set dynamically per CompanyEmail
        ],

        'mailtrap-sdk' => [
            'transport' => 'mailtrap-sdk',
            'host' => env('MAILTRAP_HOST', 'send.api.mailtrap.io'),
            'apiKey' => env('MAILTRAP_API_KEY'),
        ],

        'mailtrap' => [
            // Alias to the Mailtrap API transport to avoid accidentally using SMTP.
            'transport' => 'mailtrap-sdk',
        ],

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'auth_mode' => null,
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],
    ],

    'markdown' => [
        'theme' => 'default',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
