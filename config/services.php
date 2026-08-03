<?php

return [
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),

    'url_shortener' => [
        'enabled' => env('URL_SHORTENER_ENABLED', true),
    ],

    'plaid' => [
        'env' => env('PLAID_ENV'),
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'task_extraction_model' => env('OPENAI_TASK_EXTRACTION_MODEL', 'gpt-4.1'),
        'vendor_suggestion_model' => env('OPENAI_VENDOR_SUGGESTION_MODEL', 'gpt-4o'),
        'vendor_suggestion_fallback_model' => env('OPENAI_VENDOR_SUGGESTION_FALLBACK_MODEL', 'gpt-4o'),
    ],

    'assemblyai' => [
        'api_key' => env('ASSEMBLYAI_API_KEY'),
    ],

    'amazon' => [
        'client_id' => env('AMAZON_CLIENT_ID'),
        'client_secret' => env('AMAZON_CLIENT_SECRET'),
        'aws_access_key_id' => env('AMAZON_AWS_ACCESS_TOKEN'),
        'aws_secret_access_key' => env('AMAZON_AWS_SECRET_TOKEN'),
        'aws_region' => env('AMAZON_AWS_REGION', 'us-east-1'),
        'sp_api_endpoint' => env('AMAZON_SP_API_ENDPOINT', 'https://sellingpartnerapi-na.amazon.com'),
        'rotation_scope' => env('AMAZON_SPAPI_ROTATION_SCOPE', 'sellingpartnerapi::client_credential:rotation'),
        'rotation_queue_url' => env('AMAZON_SPAPI_ROTATION_QUEUE_URL'),
        'rotation_queue_region' => env('AMAZON_SPAPI_ROTATION_QUEUE_REGION', env('AMAZON_AWS_REGION', 'us-east-1')),
    ],

    'brightdata' => [
        'api_token' => env('BRIGHTDATA_API_TOKEN'),
        // SERP API zone name (e.g. "hive_serp_api"). Synchronous Google search via /request.
        'serp_zone' => env('BRIGHTDATA_SERP_ZONE'),
    ],

    'azure_cu' => [
        'endpoint'       => env('AZURE_CU_ENDPOINT'),
        'api_key'        => env('AZURE_CU_API_KEY'),
        'api_version'    => env('AZURE_CU_API_VERSION', '2025-11-01'),
        'analyzer_id'    => env('AZURE_CU_ANALYZER_ID', 'hive_Receipts_1'),
        'analyzer_id_coi' => env('AZURE_CU_ANALYZER_ID_COI', 'hive_COI_1'),
        'analyzer_id_material_order' => env('AZURE_CU_ANALYZER_ID_MATERIAL_ORDER', 'hive_MaterialOrder_1'),
        'analyzer_id_state_license' => env('AZURE_CU_ANALYZER_ID_STATE_LICENSE', 'hive_StateLicense_1'),
        'analyzer_id_receipt_classifier' => env('AZURE_CU_ANALYZER_ID_RECEIPT_CLASSIFIER', 'hive_ReceiptClassifier_1'),
        'analyzer_id_check_statement' => env('AZURE_CU_ANALYZER_ID_CHECK_STATEMENT', 'hive_CheckStatement_1'),
        'analyzer_id_check' => env('AZURE_CU_ANALYZER_ID_CHECK', 'hive_Check_1'),
        'analyzer_id_waiver' => env('AZURE_CU_ANALYZER_ID_WAIVER', 'HiveWaivers20261'),
    ],

    'ocr_space' => [
        'api_key' => env('OCR_SPACE_API'),
        'endpoint' => env('OCR_SPACE_ENDPOINT', 'https://api.ocr.space/parse/image'),
    ],

    'sms' => [
        'provider' => 'telnyx',
    ],

    'telnyx' => [
        'api_key' => env('TELNYX_API_KEY'),
        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
        'from' => env('TELNYX_FROM'),
        'numbers' => array_values(array_unique(array_filter(array_map('trim', explode(',', env('TELNYX_NUMBERS', env('TELNYX_FROM', ''))))))),
        'dev_to' => env('TELNYX_DEV_TO'),
        'connection_id' => env('TELNYX_CONNECTION_ID'),
        'voice_forward_to' => env('TELNYX_VOICE_FORWARD_TO'),
        'voice_timeout' => env('TELNYX_VOICE_TIMEOUT', 30),
        // Comma-separated codec preference (highest fidelity first). Wideband
        // codecs (OPUS ≤48kHz, AMR-WB/G722 16kHz "HD Voice") double the audio
        // bandwidth of legacy 8kHz G.711, giving clearer recordings and better
        // transcription — but ONLY on legs where every hop supports them.
        // PSTN/mobile endpoints commonly fall back to PCMU/PCMA (8kHz); Telnyx
        // downgrades gracefully when a preferred codec can't be negotiated.
        'preferred_codecs' => env('TELNYX_PREFERRED_CODECS', 'OPUS,AMR-WB,G722,PCMU,PCMA'),
        'hold_audio_url' => env('TELNYX_HOLD_AUDIO_URL'),
        'tts_voice' => env('TELNYX_TTS_VOICE', 'Azure.en-US-AvaMultilingualNeural'),
        'tts_voice_type' => env('TELNYX_TTS_VOICE_TYPE', 'azure'),
        'tts_rate' => env('TELNYX_TTS_RATE', '+10%'),
        'public_url' => env('TELNYX_PUBLIC_URL'),

        // Call-control HTTP hardening. Without an explicit timeout a slow
        // Telnyx API response blocks the webhook handler (and its worker)
        // indefinitely, leaving calls silent/stuck. Transient connection
        // errors are retried with the same idempotent command_id so a retry
        // never double-speaks or double-dials.
        'command_timeout' => (int) env('TELNYX_COMMAND_TIMEOUT', 10),
        'command_connect_timeout' => (int) env('TELNYX_COMMAND_CONNECT_TIMEOUT', 5),
        'command_retries' => (int) env('TELNYX_COMMAND_RETRIES', 2),

        // Ed25519 public key (base64) from the Telnyx Mission Control portal
        // used to verify inbound webhook signatures. When empty, signature
        // verification is skipped (e.g. local dev) — set it in production to
        // reject forged webhooks.
        'public_key' => env('TELNYX_PUBLIC_KEY'),
        // Max age (seconds) of a webhook timestamp before it is rejected as a
        // replay. Telnyx recommends a small tolerance for clock skew.
        'webhook_tolerance' => (int) env('TELNYX_WEBHOOK_TOLERANCE', 300),

        // Optional pre-recorded audio (publicly reachable URL) played as a
        // safety net when a TTS `speak` command fails outright, so the caller
        // hears something instead of dead silence.
        'tts_fallback_audio_url' => env('TELNYX_TTS_FALLBACK_AUDIO_URL'),
    ],

    'ipqualityscore' => [
        'api_key' => env('IPQS_API_KEY'),
    ],

    'mailtrap-sdk' => [
        'host' => env('MAILTRAP_HOST', 'send.api.mailtrap.io'),
        'apiKey' => env('MAILTRAP_API_KEY'),
        'inboxId' => env('MAILTRAP_INBOX_ID'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'anticaptcha' => [
        'api_key' => env('ANTICAPTCHA_API_KEY'),
    ],

    'twocaptcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],

    'geoapify' => [
        // Request-path calls must fail fast rather than hold a page open.
        'timeout' => (float) env('GEOAPIFY_TIMEOUT', 4),
        'connect_timeout' => (float) env('GEOAPIFY_CONNECT_TIMEOUT', 2),
        'key' => env('GEOAPIFY_API_KEY'),
    ],

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'http://localhost')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
