<?php

/*
|--------------------------------------------------------------------------
| Permit packets
|--------------------------------------------------------------------------
| The letterhead of the permit cover letter, per company (vendor id). It
| reads like the business card — the full badge logo over the owners' names,
| the office email, phone and website — not like the website's header.
| Anything not set here comes from the vendor's own record.
*/
return [
    'letterhead' => [
        // Owners as the card prints them. Without an entry the legal first
        // names of the company's call recipients are joined ("Grzegorz &
        // Patryk Szady").
        'names' => [
            1 => 'Gregory & Patryk Szady',
        ],
        // The full badge, taken from MARKETING 2020/LOGOS/LAWN SIGN 2024.pdf
        // (vector), rendered at 300 dpi and trimmed. Paths under public/.
        'logos' => [
            1 => 'images/letterhead/gs-construction.png',
        ],
    ],
];
