<?php

return [

    'channels' => [
        'move_channel' => [
            'driver' => 'single',
            'path' => storage_path('logs/move_channel.log'),
            'level' => 'debug',
        ],

        'hd_rebates' => [
            'driver' => 'daily',
            'path' => storage_path('logs/hd_rebates.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        'hd_rebates_errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/hd_rebates_errors.log'),
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

        'plaid_transaction_removal' => [
            'driver' => 'single',
            'path' => storage_path('logs/plaid_transaction_removal.log'),
            'level' => 'debug',
            'days' => 30,
        ],

        // 'nylas_connection_errors' => [
        //     'driver' => 'daily',
        //     'path' => storage_path('logs/schedule.log'),
        //     'level' => 'debug',
        //     'days' => 30,
        // ],
    ],

];
