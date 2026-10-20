<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service calls from client emails
    |--------------------------------------------------------------------------
    |
    | A known client writing about a problem on finished work ("the grout is
    | cracking in both bathrooms") opens a service call: the project moves to
    | Service Call, a pending task is created, and the client is emailed and
    | texted the schedule page to pick times. See
    | App\Services\ServiceCalls\ServiceCallEmailIntake.
    |
    | Off outside production by default: the dev database is a copy of prod
    | with live mailbox grants and SMS keys, and it must never answer real
    | clients.
    |
    */

    'email_intake' => [
        'enabled' => (bool) env('SERVICE_CALL_EMAIL_INTAKE', env('APP_ENV') === 'production'),

        // How sure the model must be before anything is written or sent.
        'min_confidence' => 0.8,

        // Complete and Service Call. An Active job's problems are part of the
        // job; a cancelled or estimate-stage project has nothing to service.
        'eligible_status_codes' => [7, 8],

        // Older mail is never acted on (a re-read after an outage must not
        // answer a week-old email as if it just arrived).
        'max_age_days' => 3,

        'model' => env('OPENAI_SERVICE_CALL_MODEL', env('OPENAI_TASK_EXTRACTION_MODEL', 'gpt-4.1')),
    ],

];
