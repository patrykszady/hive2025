<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'google' => [
        'custom_search_api_key' => env('GOOGLE_CSE_API_KEY'),
        'custom_search_cx'     => env('GOOGLE_CSE_ID'),
    ],

    'azure_cu' => [
        'endpoint'       => env('AZURE_CU_ENDPOINT'),
        'api_key'        => env('AZURE_CU_API_KEY'),
        'api_version'    => env('AZURE_CU_API_VERSION', '2025-11-01'),
        'analyzer_id'    => env('AZURE_CU_ANALYZER_ID', 'hive_Receipts_1'),
        'analyzer_id_coi' => env('AZURE_CU_ANALYZER_ID_COI', 'hive_COI_1'),
        'analyzer_id_material_order' => env('AZURE_CU_ANALYZER_ID_MATERIAL_ORDER', 'hive_MaterialOrder_1'),
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
        'hold_audio_url' => env('TELNYX_HOLD_AUDIO_URL'),
        'tts_voice' => env('TELNYX_TTS_VOICE', 'Azure.en-US-AvaMultilingualNeural'),
        'tts_voice_type' => env('TELNYX_TTS_VOICE_TYPE', 'azure'),
        'public_url' => env('TELNYX_PUBLIC_URL'),
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

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'http://localhost')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
