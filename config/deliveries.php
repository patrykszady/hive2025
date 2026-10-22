<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery texts from suppliers
    |--------------------------------------------------------------------------
    |
    | A supplier texting a delivery ("Delivery is scheduled for Wed 10/28
    | 12-3pm at 949 SOUTH ELM COURT, PALATINE … order# S2472002") becomes a
    | task on the open project at that address, and the admins are alerted.
    | Nothing is ever texted back. See
    | App\Services\Deliveries\DeliveryTextIntake.
    |
    | Off outside production by default: the dev database is a copy of prod
    | and its texts are real ones.
    |
    */

    'sms_intake' => [
        'enabled' => (bool) env('DELIVERY_TEXT_INTAKE', env('APP_ENV') === 'production'),

        'model' => env('DELIVERY_TEXT_MODEL', 'gpt-4.1'),

        // Below this the reader's "is a delivery" is not trusted.
        'min_confidence' => 0.7,
    ],

];
