<?php

return [
    /*
    | In-person meetings recorded from Hive's Record button
    | (App\Livewire\Meetings\MeetingRecorder). Transcription and summarizing
    | follow config/call_recording.php (AssemblyAI models, languages,
    | summarization driver); only what differs for meetings lives here.
    */

    /* Disk the audio is kept on — the same one call recordings use. */
    'disk' => env('MEETING_RECORDING_DISK', env('CALL_RECORDING_DISK', 'local')),

    /* Days the audio and transcript are kept, like call recordings. */
    'retention_days' => (int) env('MEETING_RECORDING_RETENTION_DAYS', env('CALL_RECORDING_RETENTION_DAYS', 180)),

    /* Longest meeting accepted, in minutes; the browser stops recording at it. */
    'max_minutes' => (int) env('MEETING_RECORDING_MAX_MINUTES', 240),

    /* Largest single uploaded piece, in kilobytes (pieces are ~10 s each). */
    'max_chunk_kb' => (int) env('MEETING_RECORDING_MAX_CHUNK_KB', 10240),

    /* Largest recording accepted as an uploaded file (Voice Memos, a handheld recorder), in megabytes. */
    'max_upload_mb' => (int) env('MEETING_RECORDING_MAX_UPLOAD_MB', 1024),

    /* An uploaded file is posted in pieces this big, in kilobytes (keep it under max_chunk_kb). */
    'upload_piece_kb' => (int) env('MEETING_RECORDING_UPLOAD_PIECE_KB', 5120),

    /* Joining, transcribing and summarizing runs here (it waits on AssemblyAI). */
    'queue' => env('MEETING_RECORDING_QUEUE', 'long-running'),
];
