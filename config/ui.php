<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cards v2 — Flux 2.21's card parts for every card and modal
    |--------------------------------------------------------------------------
    |
    | The shared chrome (x-island-card, x-details.card, x-index-table,
    | x-filter-card, x-form-modal) renders Flux's card parts — header,
    | heading, subheading, actions, body, footer — instead of hive's own
    | markup when this is on AND the page is one of the paths below. Off
    | everywhere by default; dev turns it on to adjust a page or two before
    | the whole site moves (Patryk, 2026-11-06). App\Support\Ui\CardsV2.
    |
    */

    'cards_v2' => (bool) env('UI_CARDS_V2', false),

    // Page paths (prefixes) that use the v2 chrome while it is being adjusted;
    // empty = every page.
    'cards_v2_paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('UI_CARDS_V2_PATHS', '/expenses,/hub'))))),

];
