<?php

return [
    // Core credentials (read from env here only; use config() elsewhere)
    'client_id' => env('NYLAS_CLIENT_ID'),
    'api_key' => env('NYLAS_API_KEY'),
    'redirect_uri' => env('NYLAS_REDIRECT_URI'),
    'pkce_code_verifier' => env('NYLAS_PKCE_CODE_VERIFIER', 'nylas'),
    
    // Message fetching limits
    'message_limit' => 25,
];
