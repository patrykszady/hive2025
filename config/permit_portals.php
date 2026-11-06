<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Village permit portals
    |--------------------------------------------------------------------------
    |
    | Where hive can ask what house a permit number belongs to. A village's
    | permit receipt names no address, only the permit ("Permit:
    | BLDR-026546-2026 | Invoice: INV-00044895" — Palatine, 2026-11-05), so
    | the expense matcher asks the village's own portal and matches the
    | answer against the projects like any other site address.
    |
    | A portal applies to an expense when every word in `vendor_match` is in
    | the expense vendor's business name. `tenant`/`tenant_id` are the Tyler
    | EnerGov Citizen Self Service tenant headers the portal's own front end
    | sends; the search request itself is resources/permits/energov-global-
    | search.json with the permit number as the keyword.
    |
    */

    'portals' => [
        'palatine' => [
            'name' => 'Village of Palatine',
            'driver' => 'energov',
            'base_url' => 'https://permits.palatine.il.us/EnerGov_Prod/selfservice',
            'tenant' => 'PalatineILProd',
            'tenant_id' => '1',
            'vendor_match' => ['palatine'],
        ],
    ],

    'timeout' => 20,

];
