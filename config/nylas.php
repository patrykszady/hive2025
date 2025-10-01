<?php

return [
    // Core credentials (read from env here only; use config() elsewhere)
    'client_id' => env('NYLAS_CLIENT_ID'),
    'api_key' => env('NYLAS_API_KEY'),
    'redirect_uri' => env('NYLAS_REDIRECT_URI'),
    'pkce_code_verifier' => env('NYLAS_PKCE_CODE_VERIFIER', 'nylas'),
    
    // Message fetching limits
    'message_limit' => 25,
    'message_limit_days' => 10,

    // Centralized receipts processing (forward target)
    'receipts_grant_id' => env('NYLAS_HIVE_RECEIPTS_GRANT_ID'),
    'receipts_email' => env('NYLAS_HIVE_RECEIPTS_EMAIL'),
    'receipts_deleted_folder_id' => env('NYLAS_HIVE_RECEIPTS_DELETED_FOLDER_ID'),

    // Insurance / certificates mailbox
    'insurance_grant_id' => env('NYLAS_HIVE_INSURANCE_GRANT_ID'),

    // OAuth scopes required (space separated). Ensure the Nylas Connect flow requests email.send for forwarding.
    // Add NYLAS_SCOPES to .env to override if needed.
    'scopes' => env('NYLAS_SCOPES') ?: 'email.read_only email.send',
];
