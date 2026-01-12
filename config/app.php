<?php

use Illuminate\Support\Facades\Facade;

return [
    'log_max_files' => 180,

    /*
    |--------------------------------------------------------------------------
    | Dev Webhook URL
    |--------------------------------------------------------------------------
    |
    | When set, this URL is used for webhook callbacks in development (e.g.,
    | vendor availability SMS links). Use ngrok or expose to create a public
    | tunnel to your local environment.
    |
    */

    'dev_webhook_url' => env('DEV_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // ... rest of config
];
