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
    */
    'channels' => env('CALL_RECORDING_CHANNELS', 'single'), // single|dual
    'format' => env('CALL_RECORDING_FORMAT', 'mp3'),       // mp3|wav
    'play_beep' => (bool) env('CALL_RECORDING_PLAY_BEEP', true),

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
    | Speech-to-text. Default driver is AssemblyAI (Universal-2) which provides
    | speaker diarization for multi-party calls. Polish, Spanish, and English
    | are supported. Set `language` to a 2-letter ISO code (e.g. 'en', 'pl')
    | to force a language; null lets AssemblyAI auto-detect.
    */
    'transcription' => [
        'enabled' => (bool) env('CALL_TRANSCRIPTION_ENABLED', true),
        'driver' => env('CALL_TRANSCRIPTION_DRIVER', 'assemblyai'), // assemblyai|openai
        'model' => env('CALL_TRANSCRIPTION_MODEL', 'whisper-1'),    // openai fallback
        'language' => env('CALL_TRANSCRIPTION_LANGUAGE', null),
        'speaker_labels' => (bool) env('CALL_TRANSCRIPTION_SPEAKER_LABELS', true),
        'poll_interval_seconds' => (int) env('CALL_TRANSCRIPTION_POLL_INTERVAL', 3),
        'poll_timeout_seconds' => (int) env('CALL_TRANSCRIPTION_POLL_TIMEOUT', 600),
    ],

    /*
    | AI summarization. Always English, even for non-English calls.
    */
    'summarization' => [
        'enabled' => (bool) env('CALL_SUMMARIZATION_ENABLED', true),
        'driver' => 'openai',
        'model' => 'gpt-4o',
        'output_language' => 'English',
    ],
];
