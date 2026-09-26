<?php

use Illuminate\Support\Facades\Facade;

return [
    'url' => env('APP_URL', 'https://hub.hive.contractors'),
    'log_max_files' => 180,

    /*
    |--------------------------------------------------------------------------
    | Dev Webhook URL
    |--------------------------------------------------------------------------
    |
    | When set, this URL is used for webhook callbacks in development (e.g.,
    | vendor availability SMS links). Use Hookdeck to create a public URL
    | to your local environment.
    |
    */

    'dev_webhook_url' => env('DEV_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | No-Index Hosts
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of hosts that should not be indexed by search
    | engines (e.g., dev/staging subdomains).
    |
    */

    'noindex_hosts' => env('NOINDEX_HOSTS', 'dev.hive.contractors,hub.hive.contractors'),

    /*
    |--------------------------------------------------------------------------
    | Public Marketing Site URL
    |--------------------------------------------------------------------------
    |
    | The host the public marketing pages (the /{locale}/welcome tree) are
    | canonically reached on — hive.contractors. The app shares that host
    | (moving it to hub.hive.contractors was declined on 2026-09-26: every
    | installed home-screen app starts at '/' on this host), but this stays
    | separate from APP_URL all the same. Every absolute URL the marketing
    | pages emit about themselves — the
    | sitemap, canonical tags, hreflang alternates — is rooted here, never on
    | APP_URL or the ambient request host, so they stay correct no matter
    | which host actually served the request.
    |
    */

    'marketing_url' => env('MARKETING_URL', 'https://hive.contractors'),

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

    /*
    |--------------------------------------------------------------------------
    | Fake "today" for the browser
    |--------------------------------------------------------------------------
    |
    | Testing aid: "-1 day", "2026-12-26", etc. Read through config (never
    | env() at runtime) so it behaves identically whether or not the deploy
    | has cached the configuration.
    |
    */

    'fake_browser_date' => env('FAKE_BROWSER_DATE'),

    /*
    |--------------------------------------------------------------------------
    | Physical Business Address
    |--------------------------------------------------------------------------
    |
    | The physical mailing address for legal notices, privacy policy, etc.
    |
    */

    'physical_address' => env('APP_PHYSICAL_ADDRESS', ''),

    /*
    |--------------------------------------------------------------------------
    | Long Application Name
    |--------------------------------------------------------------------------
    |
    | The full legal name of the application for legal documents.
    |
    */

    'long_name' => env('LONG_APP_NAME', 'Hive Contractors'),
];
