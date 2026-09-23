<?php

return [
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),

    // Timelapse frame registration (scripts/align_frame.py). The venv keeps
    // opencv out of the system Python; provision the same path on the server.
    'timelapse_align' => [
        'python' => env('TIMELAPSE_ALIGN_PYTHON', '/home/patryk/.venvs/hive-cv/bin/python'),
        'min_inliers' => (int) env('TIMELAPSE_ALIGN_MIN_INLIERS', 25),
        // Share of the canvas a warp may leave with no photo behind it before
        // the alignment is refused. Past this the frame is kept unaligned —
        // an honest shot beats one that is largely invented border.
        'max_border' => (float) env('TIMELAPSE_ALIGN_MAX_BORDER', 0.08),
    ],

    'url_shortener' => [
        'enabled' => env('URL_SHORTENER_ENABLED', true),
    ],

    // Read through config, never env() in a view: a cached config makes
    // runtime env() null, which silently stopped analytics loading at all.
    'google_analytics' => [
        'gtag' => env('GOOGLE_ANALYTICS_GTAG'),
    ],

    'plaid' => [
        'env' => env('PLAID_ENV'),
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // The estimate generator's model. Opus 5 unless overridden.
        'estimate_model' => env('ANTHROPIC_ESTIMATE_MODEL', 'claude-opus-5'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'task_extraction_model' => env('OPENAI_TASK_EXTRACTION_MODEL', 'gpt-4.1'),
        // Embeds past estimate sections and new enquiries for the AI estimate generator's retrieval.
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
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

    'menards' => [
        // hCaptcha solving for the Imperva wall (MenardsCaptchaSolver). Read
        // that class before relying on it: the same approach already failed
        // here with a VALID token, because Imperva scores the browser.
        'twocaptcha_key' => env('TWOCAPTCHA_API_KEY'),
        // Whether the extension may ask the server to BUY a token for the
        // wall's hCaptcha. Off by default: the server clears the checkbox
        // itself (challenge_click below), and a token injected while it does
        // resets the widget under its click. Six tokens on 2026-09-14 got
        // nothing in; the one click made while the cap was exhausted did.
        'auto_solve' => (bool) env('MENARDS_AUTO_SOLVE', false),
        // Shared secret the receipt extension uses to authenticate to
        // /api/menards/receipts (scripts/menards-receipt-extension).
        'bridge_token' => env('MENARDS_BRIDGE_TOKEN'),
        // "x,y" screen position of the Imperva wall's "I am human" checkbox on
        // the :98 display. Calibrated from the storage/app/menards-wall-*.png
        // screenshot login() captures whenever it meets the wall; unset means
        // never click blind. An X-injected click here is the same input a
        // human's noVNC click sends — verified to pass on 2026-08-26.
        'challenge_click' => env('MENARDS_CHALLENGE_CLICK'),
        // Server-side signed-in browser (MenardsRemoteBrowserService).
        'chromium_binary' => env('MENARDS_CHROMIUM_BINARY'),
        // Where scripts/provision-menards-browser.sh keeps the packed extension
        // and its update manifest, and the secret path segment under which the
        // app serves them to Chrome over https (MenardsExtensionUpdateController).
        // Unset secret = the file:// update URL, which Chrome 151 ignores.
        'extension_home' => env('MENARDS_EXT_HOME', '/opt/menards-extension'),
        'update_secret' => env('MENARDS_EXTENSION_SECRET'),
        'user_data_dir' => env('MENARDS_USER_DATA_DIR'),
        'novnc_web' => env('MENARDS_NOVNC_WEB', '/usr/share/novnc'),
        // The residential proxy the server's Chrome exits through (the 2captcha
        // pool gsc's Yelp stack uses; same env names). Imperva scores the
        // droplet's own address as a bot; see App\Support\MenardsProxy. The
        // session id keeps the browser and every captcha solve on one exit.
        // MENARDS_PROXY=false keeps the browser on the droplet's own address
        // while the credentials stay set — for a pool whose exits are not yet
        // pinned to the US (Menards 403s a Russian or Brazilian exit outright).
        'proxy_enabled' => (bool) env('MENARDS_PROXY', true),
        'proxy_host' => env('CAPTCHA_PROXY_HOST'),
        'proxy_username' => env('CAPTCHA_PROXY_USERNAME'),
        'proxy_password' => env('CAPTCHA_PROXY_PASSWORD'),
        'proxy_session' => env('MENARDS_PROXY_SESSION', 'menards'),
        // `-region-us` is the only geo parameter this pool honours (sampled
        // 5/5 US exits on 2026-09-23); `-country-` and `-state-` are ignored
        // and hand out Brazilian or Russian exits, which Menards 403s outright.
        'proxy_region' => env('MENARDS_PROXY_REGION', 'us'),
        // The official 2captcha Solver extension (force-installed next to the
        // receipt extension by scripts/provision-menards-browser.sh) clears
        // the wall's hCaptcha. While it is on, the blind checkbox click stays
        // out of its way: a token landing under a click reset the widget.
        'solver_extension' => (bool) env('MENARDS_SOLVER_EXTENSION', false),
        'solver_extension_id' => env('MENARDS_SOLVER_EXTENSION_ID', 'ifibfemgeogfhoebkmokieepdoobkbpo'),
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
        // How long a click-to-call target rings before we give up. Carriers
        // divert to voicemail after 25–30 s; at 20 s (the shared voice
        // timeout on production) five of six unanswered outbound calls in the
        // week to 2026-09-17 were cancelled by us at exactly 20 s, and the
        // user heard "did not answer" instead of the target's voicemail.
        'click_to_call_timeout' => (int) env('TELNYX_CLICK_TO_CALL_TIMEOUT', 45),
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
        // A call-control command normally answers in 0.1–0.3 s (measured from
        // the Forge box, 2026-09-17). At 10 s a stalled command held the
        // webhook worker for the full 10 s before the retry went through —
        // three outbound calls on 16–17 Sep took 10–12 s from the target
        // picking up to audio, and Telnyx re-sent the webhook meanwhile.
        // 4 s gives a stall one quarter of that cost; the retry still wins.
        'command_timeout' => (int) env('TELNYX_COMMAND_TIMEOUT', 4),
        'command_connect_timeout' => (int) env('TELNYX_COMMAND_CONNECT_TIMEOUT', 3),
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

    // The Azure app behind the Nylas Microsoft connector — used by
    // nylas:rotate-microsoft-secret to renew its own client secret before
    // the 2-year expiry (the 2026-08-11 outage).
    'ms_graph' => [
        'tenant_id' => env('MS_GRAPH_TENANT_ID'),
        'client_id' => env('MS_GRAPH_CLIENT_ID'),
        'client_secret' => env('MS_GRAPH_CLIENT_SECRET'),
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

    /*
    | TrackMyVendor — COI / license / W-9 tracking for subcontractors.
    |
    | Webhook-only by design: there is no REST API and no API key. You register
    | our endpoint under Settings → Integrations → Webhook Endpoints and it
    | pushes compliance events to us, signed with X-TMV-Signature (HMAC-SHA256
    | of the raw body). Free for the first 25 vendors, webhooks included on
    | every plan.
    */
    'trackmyvendor' => [
        'webhook_secret' => env('TRACKMYVENDOR_WEBHOOK_SECRET'),
    ],

    'anticaptcha' => [
        'api_key' => env('ANTICAPTCHA_API_KEY'),
    ],

    'twocaptcha' => [
        'api_key' => env('TWOCAPTCHA_API_KEY'),
    ],

    // EWCCV — workers comp coverage verification (ewccv.com).
    'ewccv' => [
        // Issued by NCCI. Skips reCAPTCHA entirely via
        // /recaptcha/verifybypasskey — the sanctioned automation path.
        'bypass_key' => env('EWCCV_BYPASS_KEY'),
        // Shared secret for the browser-extension session bridge
        // (scripts/ewccv-session-bridge). The server cannot pass EWCCV's
        // reCAPTCHA v3; a real browser can, so it hands the session over.
        'bridge_token' => env('EWCCV_BRIDGE_TOKEN'),
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

    /*
    | Cloudflare — edge cache purging (see CloudflarePurgeCache).
    |
    | The token must carry an "All Domains" (zone) policy. An account-scoped
    | policy cannot purge or touch DNS even with every permission ticked —
    | that cost an hour on 2026-08-19.
    */
    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
    ],

    /*
    | gs.construction's admin API (the site ss.systems reads its leads from).
    | Every lead born here — crew inbox, Angi, Houzz, the hive form — is
    | pushed there the moment it exists (MirrorLeadToGsc), so it shows on
    | ss.systems first. Unset = no push (the 15-minute pull is the fallback).
    */
    'gsc' => [
        'url' => env('GSC_API_URL'),
        'token' => env('GSC_ADMIN_API_TOKEN'),
    ],
];
