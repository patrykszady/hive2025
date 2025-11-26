<?php

return [
    'channels' => [
        'schedule' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/schedule.log'),
            'level'  => 'error',
            'days'   => 30,
        ],

        'move_channel' => [
            'driver' => 'daily',
            'path' => storage_path('logs/move_channel.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'company_emails_login_error' => [
            'driver' => 'daily',
            'path' => storage_path('logs/company_emails_login_error.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'plaid_adds' => [
            'driver' => 'daily',
            'path' => storage_path('logs/plaid_adds.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'plaid_skips' => [
            'driver' => 'daily',
            'path' => storage_path('logs/plaid_skips.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'plaid_statements' => [
            'driver' => 'daily',
            'path' => storage_path('logs/plaid_statements.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'add_check_id_to_transactions' => [
            'driver' => 'daily',
            'path' => storage_path('logs/add_check_id_to_transactions.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'ms_form_amount_not_found' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ms_form_amount_not_found.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'angi_webhook_results' => [
            'driver' => 'daily',
            'path' => storage_path('logs/angi_webhook_results.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'leads_in_email_error' => [
            'driver' => 'daily',
            'path' => storage_path('logs/leads_in_email_error.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'vendor_docs' => [
            'driver' => 'daily',
            'path' => storage_path('logs/vendor_docs.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'google_places' => [
            'driver' => 'daily',
            'path' => storage_path('logs/google_places.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'task_reminder' => [
            'driver' => 'daily',
            'path' => storage_path('logs/task_reminder.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'receipt_processing' => [
            'driver' => 'daily',
            'path' => storage_path('logs/receipt_processing.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'nylas' => [
            'driver' => 'daily',
            'path' => storage_path('logs/nylas.log'),
            'level' => 'info',
            'days' => 30
        ],

        'nylas_webhooks' => [
            'driver' => 'daily',
            'path' => storage_path('logs/nylas_webhooks.log'),
            'level' => 'debug',
            'days' => 30,
        ],
    ],
];
