<?php

/*
|--------------------------------------------------------------------------
| Public-site locales
|--------------------------------------------------------------------------
|
| Languages the public marketing site (hive.contractors) is available in.
| The default locale ('en') is served un-prefixed at /welcome; every other
| locale is served under its code, e.g. /pl/welcome and /es/welcome.
|
| 'native' is the label shown in the language switcher.
|
*/

return [

    'default' => 'en',

    'supported' => [
        'en' => ['native' => 'English', 'name' => 'English', 'hreflang' => 'en'],
        'pl' => ['native' => 'Polski', 'name' => 'Polish', 'hreflang' => 'pl'],
        'es' => ['native' => 'Español', 'name' => 'Spanish', 'hreflang' => 'es'],
    ],

];
