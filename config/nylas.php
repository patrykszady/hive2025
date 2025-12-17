<?php

return [
    // Core credentials (read from env here only; use config() elsewhere)
    'client_id' => env('NYLAS_CLIENT_ID'),
    'api_key' => env('NYLAS_API_KEY'),
    'redirect_uri' => env('NYLAS_REDIRECT_URI'),
    'pkce_code_verifier' => env('NYLAS_PKCE_CODE_VERIFIER', 'nylas'),
    
    // Message fetching limits
    'message_limit' => 15,
    'message_limit_days' => 7, // Reduced from 20 to avoid provider timeouts
    'full_fetch_soft_cap' => env('NYLAS_FULL_FETCH_SOFT_CAP', 0),

    // Centralized receipts processing (forward target)
    'receipts_grant_id' => '957bf081-f050-459f-a4cd-7d4423113a22',
    'receipts_email' => 'receipts@hive.contractors',
    'receipts_inbox_folder_id' => 'AQMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAADgszYMNzUTkubimPmDhMPhQEAAAD-2jF9AnVIlnk3z1Yvx18AAAIBDAAAAA==',
    'receipts_deleted_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAAAmeNeAAA=',
    'hive_receipts_duplicate_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYGAAA=',
    'hive_receipts_error_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYEAAA=',
    'hive_receipts_need_to_add_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYFAAA=',
    'hive_receipts_saved_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYCAAA=',
    'hive_receipts_test_folder_id' => 'AAMkADM1MTY5NmIzLWY3M2EtNDY5Yi1hYzIzLWE5YzA5NTBkOWE5NgAuAAAAAACCzNgw3NROS5uKY_YOEw_FAQAA-9oxfQJ1SJZ5N89WL8dfAAABooYIAAA=',

    // Certificates mailbox on Hive Email
    'certificates_grant_id' => '649561d4-4652-4963-b23c-ce214ea58255',
    'certificates_email' => 'certificates@hive.contractors',
    'certificates_inbox_folder_id' => 'AQMkADFjNDA4YWRkAC1kMGNhLTRkYzctOTBmNi1iM2ZkZAFmODFkNmQALgAAAxsDHh30a1RFueZPWERXXM8BAEprXEv7KyZGkzyld2nc1A8AAAIBDAAAAA==',
    'certificates_saved_folder_id' => 'AAMkADFjNDA4YWRkLWQwY2EtNGRjNy05MGY2LWIzZmRkZGY4MWQ2ZAAuAAAAAAAbAx4d9GtURbnmT1hEV1zPAQBKa1xL_ysmRpM8pXdp3NQPAAAIbJ_IAAA=',
    'certificates_error_folder_id' => 'AAMkADFjNDA4YWRkLWQwY2EtNGRjNy05MGY2LWIzZmRkZGY4MWQ2ZAAuAAAAAAAbAx4d9GtURbnmT1hEV1zPAQBKa1xL_ysmRpM8pXdp3NQPAAAIbJ_JAAA=',
    'certificates_deleted_folder_id' => 'AQMkADFjNDA4YWRkAC1kMGNhLTRkYzctOTBmNi1iM2ZkZAFmODFkNmQALgAAAxsDHh30a1RFueZPWERXXM8BAEprXEv7KyZGkzyld2nc1A8AAAIBCgAAAA==',

    // OAuth scopes required (space separated). Ensure the Nylas Connect flow requests email.send for forwarding.
    // Add NYLAS_SCOPES to .env to override if needed.
    'scopes' => env('NYLAS_SCOPES') ?: 'email.read_only email.send',

    // Tracking configuration
    // Note: "opens" tracking is still pixel-based under the hood, but Nylas may provide per-recipient
    // attribution via webhook payloads (e.g. recipient_email). When enabled, we can ignore sender opens.
    'tracking' => [
        'opens' => (bool) env('NYLAS_TRACK_OPENS', env('APP_ENV') === 'production'),
        'custom_pixel_opens' => (bool) env('NYLAS_CUSTOM_PIXEL_OPENS', true),
        // When true and opens=true, we will also record custom pixel opens as event_type=opened_pixel
        // so you can compare them against Nylas webhook opens.
        'compare_opens' => (bool) env('NYLAS_COMPARE_OPENS', env('APP_ENV') === 'production'),
    ],
];
