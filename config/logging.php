<?php

return [
    'channels' => [
        'schedule' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/schedule.log'),
            'level'  => 'info',
            'days'   => 14, // or however many you want to keep
        ],

        'move_channel' => [
            'driver' => 'single',
            'path' => storage_path('logs/move_channel.log'),
            'level' => 'debug',
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
            'driver' => 'single',
            'path' => storage_path('logs/leads_in_email_error.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'ms_message_error_folder' => [
            'driver' => 'single',
            'path' => storage_path('logs/ms_message_error_folder.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'plaid_transaction_removal' => [
            'driver' => 'single',
            'path' => storage_path('logs/plaid_transaction_removal.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'vendor_docs' => [
            'driver' => 'single',
            'path' => storage_path('logs/vendor_docs.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'google_places' => [
            'driver' => 'single',
            'path' => storage_path('logs/vendor_docs.log'),
            'level' => 'debug',
            'days' => 30,
        ],
    ],

];
