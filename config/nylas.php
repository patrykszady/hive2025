<?php

return [
    // Core credentials (read from env here only; use config() elsewhere)
    'client_id' => env('NYLAS_CLIENT_ID'),
    'api_key' => env('NYLAS_API_KEY'),
    'api_uri' => rtrim(env('NYLAS_API_URI', 'https://api.us.nylas.com'), '/'),
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

    // Waivers mailbox on Hive Email — returned wet-signed lien waiver / GCSS
    // scans land here; the barcode ingest matches them by HLW-/HSS- codes.
    'waivers_grant_id' => '6bfc3060-7409-4b44-9b98-9e88a5ae4481',
    'waivers_email' => 'waivers@hive.contractors',
    'waivers_inbox_folder_id' => 'AQMkAGQ4MjRmM2M3LTQ1MmEtNGZiYS1hMjUxLWNiMjFiZGZjOTNjZQAuAAADZg-HbmNa8kyefF2rxPPyRwEAi7dz6mqPSUOPEb-C83eK8wAAAgEMAAAA',
    'waivers_processed_folder_id' => 'AQMkAGQ4MjRmM2M3LTQ1MmEtNGZiYS1hMjUxLWNiMjFiZGZjOTNjZQAuAAADZg-HbmNa8kyefF2rxPPyRwEAi7dz6mqPSUOPEb-C83eK8wAAAgFcAAAA',
    'waivers_saved_folder_id' => 'AQMkAGQ4MjRmM2M3LTQ1MmEtNGZiYS1hMjUxLWNiMjFiZGZjOTNjZQAuAAADZg-HbmNa8kyefF2rxPPyRwEAi7dz6mqPSUOPEb-C83eK8wAAAgFdAAAA',
    'waivers_error_folder_id' => 'AQMkAGQ4MjRmM2M3LTQ1MmEtNGZiYS1hMjUxLWNiMjFiZGZjOTNjZQAuAAADZg-HbmNa8kyefF2rxPPyRwEAi7dz6mqPSUOPEb-C83eK8wAAAgFeAAAA',
    'waivers_deleted_folder_id' => 'AQMkAGQ4MjRmM2M3LTQ1MmEtNGZiYS1hMjUxLWNiMjFiZGZjOTNjZQAuAAADZg-HbmNa8kyefF2rxPPyRwEAi7dz6mqPSUOPEb-C83eK8wAAAgEKAAAA',

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

    /*
     * crew@gs.construction lead capture.
     *
     * crew@ is a Microsoft 365 SHARED mailbox with no grant of its own. It is
     * read through an existing user grant plus Nylas' `shared_from` parameter,
     * which proxies to Microsoft Graph against the shared mailbox. Two
     * consequences worth knowing before changing anything here:
     *
     *  - Nylas native search (`search_query_native`) queries the grant's OWN
     *    synced mailbox and does NOT traverse shared folders, so searching for
     *    a message that exists in crew@ returns nothing. Only `shared_from`
     *    listing works.
     *  - For the same reason a `message.created` webhook can never fire for
     *    this mailbox — Nylas has no synced object to notify about. Polling is
     *    the only option, not a preference.
     */
    'crew_leads' => [
        'enabled' => (bool) env('NYLAS_CREW_LEADS_ENABLED', true),

        // The shared mailbox to read.
        'mailbox' => env('NYLAS_CREW_LEADS_MAILBOX', 'crew@gs.construction'),

        // Grants able to proxy that mailbox, tried in order. Both are
        // gs.construction users with access to the shared mailbox; the second
        // is a fallback for when the first grant expires.
        'grant_ids' => array_values(array_filter(explode(',', (string) env(
            'NYLAS_CREW_LEADS_GRANT_IDS',
            '023bfe85-9c79-4afa-9569-d53c8cccf25f,c8f93079-4bb5-4d5b-a2ca-8f11f41327f0',
        )))),

        // Which vendor the resulting leads belong to (GS Construction).
        'vendor_id' => (int) env('NYLAS_CREW_LEADS_VENDOR_ID', 1),

        // `leads.created_by_user_id` is NOT NULL with no default, and there is
        // no authenticated user on a scheduled run. Matches what the website
        // contact form already records for all 69 of its leads.
        'created_by_user_id' => (int) env('NYLAS_CREW_LEADS_CREATED_BY_USER_ID', 1),

        // Namespace for the dedupe key. Deliberately distinct from
        // 'gs.construction', which the website contact form already uses —
        // sharing it would collide with that form's id counter.
        'external_source' => 'crew-email',

        // How far back the FIRST run may look. After that a watermark takes
        // over and only newer mail is considered. Keeps a deploy from
        // importing years of history as fresh leads.
        'initial_lookback_days' => (int) env('NYLAS_CREW_LEADS_LOOKBACK_DAYS', 14),

        'poll_limit' => (int) env('NYLAS_CREW_LEADS_POLL_LIMIT', 25),

        // Senders that are US, not a prospect. Verified live: 3 of the 5 most
        // recent messages in that Inbox are outbound client mail from
        // support@hive.contractors, so without this every email GS sends
        // manufactures a fake lead.
        'internal_domains' => ['gs.construction', 'hive.contractors'],
    ],

    // Calendar events for Meet tasks
    'meet' => [
        'enabled' => (bool) env('NYLAS_MEET_ENABLED', true),
        'conferencing_provider' => env('NYLAS_MEET_CONFERENCING_PROVIDER', 'Microsoft Teams'),
        // In local/dev/test, all Meet invites are redirected to this address.
        'dev_recipient' => env('NYLAS_MEET_DEV_RECIPIENT', 'patryk.szady@live.com'),
    ],
];
