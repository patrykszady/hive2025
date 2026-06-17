<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Call Recording Mode
    |--------------------------------------------------------------------------
    | auto    - record every call after disclosure (default)
    | manual  - record only when explicitly started (e.g. agent button)
    | off     - do not record any calls
    */
    'mode' => env('CALL_RECORDING_MODE', 'auto'),

    /*
    | How many days to keep recordings, transcripts, and AI summaries before
    | the daily purge job deletes them. Default 180 days.
    */
    'retention_days' => (int) env('CALL_RECORDING_RETENTION_DAYS', 180),

    /*
    | Storage disk for call recording audio files.
    */
    'disk' => env('CALL_RECORDING_DISK', 'local'),

    /*
    | Telnyx record-start parameters.
    | https://developers.telnyx.com/api/call-control/start-recording
    |
    | Best practices for quality:
    | - channels: dual recommended (captures both caller & agent separately for easier transcription)
    | - format: wav (lossless PCM, best for transcription; mp3 is lossy)
    | - play_beep: true (audible compliance marker, especially important for two-party consent)
    |
    | NOTE: record_start has NO sample-rate parameter. The recording's sample
    | rate is fixed by the call's negotiated audio codec, not by record_start.
    | To raise fidelity, prefer wideband ("HD Voice") codecs on the call itself
    | via services.telnyx.preferred_codecs (G722/OPUS/AMR-WB). wav simply stores
    | whatever the codec delivers without further loss.
    */
    'channels' => env('CALL_RECORDING_CHANNELS', 'dual'),  // single|dual (dual captures each party separately)
    'format' => env('CALL_RECORDING_FORMAT', 'wav'),       // mp3|wav (wav is lossless, better for transcription)
    'play_beep' => (bool) env('CALL_RECORDING_PLAY_BEEP', false),

    /*
    | Disclosure announcement played to the called party at the very start of
    | the call, before recording begins. Kept short for IL two-party consent.
    */
    'disclosure' => [
        'enabled' => (bool) env('CALL_RECORDING_DISCLOSURE_ENABLED', true),
        'phrase' => env('CALL_RECORDING_DISCLOSURE_PHRASE', 'This call is recorded.'),
        'voice' => env('CALL_RECORDING_DISCLOSURE_VOICE', 'Azure.en-US-AvaMultilingualNeural'),
        'voice_type' => env('CALL_RECORDING_DISCLOSURE_VOICE_TYPE', 'azure'),
    ],

    /*
    | Outbound click-to-call disclosure played to the target (recipient) when
    | GS Construction initiates the call. Immediately after target answers.
    */
    'outbound_disclosure' => [
        'enabled' => (bool) env('CALL_RECORDING_OUTBOUND_DISCLOSURE_ENABLED', true),
        'phrase' => env('CALL_RECORDING_OUTBOUND_DISCLOSURE_PHRASE', 'GS Construction is calling you. This call is being recorded.'),
        'voice' => env('CALL_RECORDING_OUTBOUND_DISCLOSURE_VOICE', 'Azure.en-US-AvaMultilingualNeural'),
        'voice_type' => env('CALL_RECORDING_OUTBOUND_DISCLOSURE_VOICE_TYPE', 'azure'),
    ],

    /*
    | Speech-to-text. Default driver is AssemblyAI Universal-3 Pro (with
    | Universal-2 fallback) which provides speaker diarization. Calls are
    | always transcribed in English (`language` = 'en') regardless of the
    | language spoken on the call: the bilingual `en,pl` code-switching mode
    | hallucinated Polish on English calls and degraded diarization, so we
    | pin English. Set `language` to another 2-letter ISO code to override,
    | or to null to fall back to `language_codes` auto-detection.
    */
    'transcription' => [
        'enabled' => (bool) env('CALL_TRANSCRIPTION_ENABLED', true),
        'driver' => env('CALL_TRANSCRIPTION_DRIVER', 'assemblyai'), // assemblyai|openai
        'model' => env('CALL_TRANSCRIPTION_MODEL', 'whisper-1'),    // openai fallback
        'language' => env('CALL_TRANSCRIPTION_LANGUAGE', 'en'),

        /*
        | Comma-separated allow-list of language codes, used only as a
        | fallback when `language` above is set to null. Per AssemblyAI
        | requirements, one of the entries must be `en`. We pin English by
        | default, so this is normally inactive.
        */
        'language_codes' => env('CALL_TRANSCRIPTION_LANGUAGE_CODES', 'en,pl'),

        'speaker_labels' => (bool) env('CALL_TRANSCRIPTION_SPEAKER_LABELS', true),
        // Most calls are 2-party (caller + agent). Telling AssemblyAI to
        // expect 2 speakers significantly improves diarization when both
        // voices have similar pitch/timbre. Set to null to let the model
        // auto-detect (better for conference calls). Per AssemblyAI's
        // guidance, `speakers_expected` should only be used when you are
        // *certain* of the exact count — otherwise prefer `speaker_options`
        // (the min/max range below), which we enable by default.
        'speakers_expected' => env('CALL_TRANSCRIPTION_SPEAKERS_EXPECTED', null),

        /*
        | Range-based speaker hint (AssemblyAI `speaker_options`). This is the
        | recommended way to guide diarization when the exact speaker count
        | varies call-to-call: most calls are 2-party, voicemails are IVR +
        | caller, and the occasional call has a third party. A tight range
        | (min 2, max 3) keeps the diarizer from collapsing two similar
        | voices into a single speaker (which produced one giant unattributed
        | block) while still allowing a third party. When `speakers_expected`
        | is set it takes precedence and this is ignored.
        */
        'speaker_options' => [
            'min_speakers_expected' => (int) env('CALL_TRANSCRIPTION_MIN_SPEAKERS', 2),
            'max_speakers_expected' => (int) env('CALL_TRANSCRIPTION_MAX_SPEAKERS', 3),
        ],

        /*
        | AssemblyAI Speaker Identification. After diarization, AssemblyAI can
        | analyze the conversation content and map the generic "Speaker A/B"
        | labels to the real participants we already know about (the Hive
        | agent + the external caller). This is dramatically more reliable
        | than guessing from greeting/disclosure markers. Runs as a second
        | call to the Speech Understanding API and stores the result in the
        | transcript's `speaker_map`.
        */
        'speaker_identification' => (bool) env('CALL_TRANSCRIPTION_SPEAKER_IDENTIFICATION', true),

        /*
        | AssemblyAI Audio Intelligence add-ons. These run inline with the
        | transcription request and their results are stored in the
        | transcript's `intelligence` JSON column. Entity detection is
        | especially useful for construction calls (addresses, dates, phone
        | numbers, dollar amounts); chapters/highlights/topics aid review.
        */
        'intelligence' => [
            'entity_detection' => (bool) env('CALL_TRANSCRIPTION_ENTITY_DETECTION', true),
            'auto_highlights' => (bool) env('CALL_TRANSCRIPTION_AUTO_HIGHLIGHTS', true),
            'auto_chapters' => (bool) env('CALL_TRANSCRIPTION_AUTO_CHAPTERS', true),
            'iab_categories' => (bool) env('CALL_TRANSCRIPTION_IAB_CATEGORIES', false),
            'sentiment_analysis' => (bool) env('CALL_TRANSCRIPTION_SENTIMENT_ANALYSIS', true),
        ],


        /*
        | AssemblyAI speech model priority list. Universal-3 Pro is the most
        | accurate multilingual model and supports `keyterms_prompt`,
        | `custom_spelling`, and code-switching. Universal-2 is the fallback
        | when U-3 Pro is unavailable. Slam-1 is English-only — only useful
        | when CALL_TRANSCRIPTION_LANGUAGE=en is forced.
        */
        'speech_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CALL_TRANSCRIPTION_SPEECH_MODELS', 'universal-3-pro,universal-2'))
        ))),

        /*
        | Hard spelling rewrites passed to AssemblyAI as `custom_spelling`.
        | Keys are the canonical word AssemblyAI should output; values are
        | arrays of common mishears. Use this for proper nouns AAI keeps
        | getting wrong (Polish names, vendor names, addresses, etc.).
        */
        'name_corrections' => [
            // Hive staff with Polish-spelled names
            'Patryk' => ['Patrick', 'Patrik', 'Petrick', 'Patryck'],
            'Grzegorz' => ['Gregor', 'Gregorz', 'Gregos'],
            'Andzelina' => ['Angelina', 'Anjelina', 'Andelina'],
            // Common surname mishears (add as we encounter them)
            'Szady' => ['Vady', 'Sady', 'Zaddy', 'Shady'],
        ],

        'poll_interval_seconds' => (int) env('CALL_TRANSCRIPTION_POLL_INTERVAL', 3),
        'poll_timeout_seconds' => (int) env('CALL_TRANSCRIPTION_POLL_TIMEOUT', 600),
    ],

    /*
    | AI summarization. Always English, even for non-English calls. By
    | default this runs through AssemblyAI's LLM Gateway so the entire
    | transcript -> summary -> action-items pipeline stays with a single
    | vendor. Set the driver to `openai` to fall back to OpenAI directly.
    */
    'summarization' => [
        'enabled' => (bool) env('CALL_SUMMARIZATION_ENABLED', true),
        'driver' => env('CALL_SUMMARIZATION_DRIVER', 'openai'), // assemblyai|openai
        // OpenAI model (used when driver = openai).
        'model' => env('CALL_SUMMARIZATION_MODEL', 'gpt-4o'),
        // AssemblyAI LLM Gateway model (used when driver = assemblyai). Must
        // support structured outputs — Claude 4.5+, GPT-4.1/5.x, Gemini, etc.
        'assemblyai_model' => env('CALL_SUMMARIZATION_ASSEMBLYAI_MODEL', 'claude-sonnet-4-6'),
        'output_language' => 'English',
    ],
];
