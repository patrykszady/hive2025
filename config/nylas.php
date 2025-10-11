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
    'full_fetch_soft_cap' => env('NYLAS_FULL_FETCH_SOFT_CAP', 0),

    // Centralized receipts processing (forward target)
    'receipts_grant_id' => env('NYLAS_HIVE_RECEIPTS_GRANT_ID'),
    'receipts_email' => env('NYLAS_HIVE_RECEIPTS_EMAIL'),
    'receipts_deleted_folder_id' => env('NYLAS_HIVE_RECEIPTS_DELETED_FOLDER_ID'),
    'hive_receipts_duplicate_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYBAAA=',
    'hive_receipts_error_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYEAAA=',
    'hive_receipts_need_to_add_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYFAAA=',
    'hive_receipts_saved_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYCAAA=',
    'hive_receipts_test_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYIAAA=',

    // Insurance / certificates mailbox
    'insurance_grant_id' => env('NYLAS_HIVE_INSURANCE_GRANT_ID'),
    'insurance_email' => env('NYLAS_HIVE_INSURANCE_EMAIL'),
    'certificates_saved_folder_id' => 'AAMkADFjNDA4YWRkLWQwY2EtNGRjNy05MGY2LWIzZmRkZGY4MWQ2ZAAuAAAAAAAbAx4d9GtURbnmT1hEV1zPAQBKa1xL_ysmRpM8pXdp3NQPAAAIbJ_IAAA=',
    'certificates_error_folder_id' => 'AAMkADFjNDA4YWRkLWQwY2EtNGRjNy05MGY2LWIzZmRkZGY4MWQ2ZAAuAAAAAAAbAx4d9GtURbnmT1hEV1zPAQBKa1xL_ysmRpM8pXdp3NQPAAAIbJ_JAAA=',

    // OAuth scopes required (space separated). Ensure the Nylas Connect flow requests email.send for forwarding.
    // Add NYLAS_SCOPES to .env to override if needed.
    'scopes' => env('NYLAS_SCOPES') ?: 'email.read_only email.send',
];
