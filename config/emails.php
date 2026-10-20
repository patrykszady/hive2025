<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stakeholder email
    |--------------------------------------------------------------------------
    |
    | Email with a project's stakeholders — client users and vendors on the
    | job — is read from every connected mailbox (inbox and sent), stored as
    | App\Models\EmailMessage with its real attachments, AI-read like a call
    | transcript, and shown with the project's calls and texts. See
    | App\Services\Emails\StakeholderEmailStore and `emails:sync`.
    |
    | Off outside production by default: the dev database is a copy of prod
    | with live mailbox grants.
    |
    */

    'stakeholder_sync' => [
        'enabled' => (bool) env('STAKEHOLDER_EMAIL_SYNC', env('APP_ENV') === 'production'),

        // First run (and a run with no watermark) looks back this far.
        'initial_days' => (int) env('STAKEHOLDER_EMAIL_INITIAL_DAYS', 30),

        // Messages per Nylas page; a run reads at most `max_pages` pages per
        // folder and resumes from there on the next run.
        'page_size' => 100,
        'max_pages' => 20,

        // The AI read every stored email gets (summary, action items, project).
        'summary_model' => env('OPENAI_EMAIL_SUMMARY_MODEL', 'gpt-4.1'),

        // Attachments worth keeping: real files, not logos and signature art.
        'attachments' => [
            'max_bytes' => 25 * 1024 * 1024,
            'max_per_message' => 15,
            // Images smaller than this are almost always signature logos or icons.
            'min_image_bytes' => 15 * 1024,
        ],
    ],

];
