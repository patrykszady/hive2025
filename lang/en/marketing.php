<?php

/*
|--------------------------------------------------------------------------
| Public marketing content — English (source of truth)
|--------------------------------------------------------------------------
|
| 'nav' holds the small UI strings for the marketing chrome. 'areas' is the
| full feature content, kept in config/marketing.php so it stays hand-
| formatted and readable; it is re-exposed here so the marketing() helper and
| trans('marketing.areas.*') read it through the translation layer. The Polish
| and Spanish files (lang/pl/marketing.php, lang/es/marketing.php) mirror this
| structure exactly — same keys, slugs, and icons — with translated strings.
|
*/

$marketing = require config_path('marketing.php');

return [

    'nav' => [
        'contractors' => 'Contractors',
        'homeowners' => 'Homeowners',
        'faq' => 'FAQ',
        'sign_in' => 'Sign in',
        'get_started' => 'Get started',
        'menu' => 'Menu',
        'language' => 'Language',
        'pages' => [
            'finances' => 'Finances',
            'estimates' => 'Estimates & Documents',
            'clients' => 'Leads & Clients',
            'vendors' => 'Vendors & Compliance',
            'planning' => 'Planning',
            'team' => 'Team & Time',
            'communication' => 'Communication',
            'photos' => 'Photos & Timelapses',
            'automation' => 'Automation & AI',
        ],
    ],

    'areas' => $marketing['areas'],

];
