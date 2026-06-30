<?php

use App\Jobs\PurgeOldCallRecordings;
use App\Jobs\SummarizeCallTranscript;
use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('call_control_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('status')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('recording_disk')->nullable();
            $table->string('recording_path')->nullable();
            $table->string('recording_telnyx_id')->nullable();
            $table->timestamp('recording_started_at')->nullable();
            $table->boolean('recording_disclosure_played')->default(false);
            $table->string('language', 8)->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->boolean('has_voicemail')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('call_transcripts')) {
        Schema::create('call_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id');
            $table->string('telnyx_recording_id')->nullable();
            $table->string('telnyx_transcription_id')->nullable();
            $table->string('engine')->nullable();
            $table->string('language', 8)->nullable();
            $table->longText('text')->nullable();
            $table->json('segments')->nullable();
            $table->json('speaker_map')->nullable();
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('summary_model')->nullable();
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->json('topics')->nullable();
            $table->json('next_steps')->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('caller_intent')->nullable();
            $table->json('intelligence')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('cell_phone')->nullable();
            $table->timestamps();
        });
    }
});

it('exposes the recording disclosure config defaults', function () {
    expect(config('call_recording.mode'))->toBe('auto');
    expect(config('call_recording.retention_days'))->toBe(180);
    expect(config('call_recording.disclosure.phrase'))->toBe('This call is recorded.');
    expect(config('call_recording.summarization.model'))->toBe('gpt-4o');
    expect(config('call_recording.summarization.driver'))->toBe('assemblyai');
});

it('summarizes a transcript via OpenAI and stores structured fields', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-1',
        'direction' => 'incoming',
        'from_number' => '+15551234567',
        'to_number' => '+12247354200',
        'status' => 'completed',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'telnyx',
        'language' => 'en',
        'text' => 'Caller asked about scheduling a roof inspection next Tuesday.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'Caller asked about a roof inspection.',
        'action_items' => ['Schedule inspection for Tuesday'],
        'topics' => ['roof', 'inspection'],
        'next_steps' => ['Confirm time with caller'],
        'sentiment' => 'neutral',
        'caller_intent' => 'schedule_service',
    ];

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    $transcript->refresh();
    expect($transcript->summary)->toBe('Caller asked about a roof inspection.');
    expect($transcript->action_items)->toBe(['Schedule inspection for Tuesday']);
    expect($transcript->sentiment)->toBe('neutral');
    expect($transcript->caller_intent)->toBe('schedule_service');
    expect($transcript->summary_model)->toBe('gpt-4o');
    expect($transcript->summarized_at)->not->toBeNull();
});

it('purges the recording when the AI flags it as a voicemail with no message left', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');

    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/no-message.mp3', 'fake-audio');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-vm',
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+17732513666',
        'status' => 'completed',
        'recording_url' => '/storage/call-recordings/no-message.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/no-message.mp3',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => "Speaker A: You've reached my voicemail, please leave a message after the tone. Speaker B: I couldn't hear you, please try again.",
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'Reached the target voicemail; no message was left.',
        'action_items' => [],
        'topics' => ['voicemail'],
        'next_steps' => [],
        'sentiment' => 'neutral',
        'caller_intent' => 'unknown',
        'recording_has_no_message' => true,
    ];

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    // Recording fields cleared, audio file deleted, transcript removed.
    $callLog->refresh();
    expect($callLog->recording_url)->toBeNull();
    expect($callLog->recording_path)->toBeNull();
    expect($callLog->recording_disk)->toBeNull();
    expect($callLog->has_voicemail)->toBeFalse();
    expect(Storage::disk('local')->exists('public/call-recordings/no-message.mp3'))->toBeFalse();
    expect(CallTranscript::find($transcript->id))->toBeNull();
});

it('keeps the recording when the AI reports a real message was left', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');

    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/has-message.mp3', 'fake-audio');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-real',
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+17732513666',
        'status' => 'completed',
        'recording_url' => '/storage/call-recordings/has-message.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/has-message.mp3',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'Speaker A: Hi Mark, this is Patryk following up on the roof estimate. Please call me back at your convenience.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'Patryk left a message about the roof estimate.',
        'action_items' => ['Mark to call Patryk back'],
        'topics' => ['roof', 'estimate'],
        'next_steps' => ['Await callback'],
        'sentiment' => 'neutral',
        'caller_intent' => 'follow_up',
        'recording_has_no_message' => false,
    ];

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    $callLog->refresh();
    expect($callLog->recording_url)->toBe('/storage/call-recordings/has-message.mp3');
    expect(Storage::disk('local')->exists('public/call-recordings/has-message.mp3'))->toBeTrue();
    expect(CallTranscript::find($transcript->id))->not->toBeNull();
});

it('summarizes a transcript via the AssemblyAI LLM Gateway by default', function () {
    config()->set('call_recording.summarization.driver', 'assemblyai');
    config()->set('call_recording.summarization.assemblyai_model', 'claude-sonnet-4-6');
    config()->set('services.assemblyai.api_key', 'test-aai-key');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-aai',
        'direction' => 'incoming',
        'from_number' => '+15551234567',
        'to_number' => '+12247354200',
        'status' => 'completed',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'Caller asked about scheduling a roof inspection next Tuesday.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'Caller asked about a roof inspection.',
        'action_items' => ['Schedule inspection for Tuesday'],
        'topics' => ['roof', 'inspection'],
        'next_steps' => ['Confirm time with caller'],
        'sentiment' => 'neutral',
        'caller_intent' => 'schedule_service',
    ];

    Http::fake([
        'llm-gateway.assemblyai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'llm-gateway.assemblyai.com')
        && $request['model'] === 'claude-sonnet-4-6');

    $transcript->refresh();
    expect($transcript->summary)->toBe('Caller asked about a roof inspection.');
    expect($transcript->action_items)->toBe(['Schedule inspection for Tuesday']);
    expect($transcript->sentiment)->toBe('neutral');
    expect($transcript->caller_intent)->toBe('schedule_service');
    expect($transcript->summary_model)->toBe('claude-sonnet-4-6');
    expect($transcript->summarized_at)->not->toBeNull();
});

it('falls back to OpenAI when the AssemblyAI LLM Gateway is not accessible', function () {
    config()->set('call_recording.summarization.driver', 'assemblyai');
    config()->set('services.assemblyai.api_key', 'test-aai-key');
    config()->set('services.openai.api_key', 'test-openai-key');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-fallback',
        'direction' => 'incoming',
        'from_number' => '+15551234567',
        'to_number' => '+12247354200',
        'status' => 'completed',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'Caller asked about scheduling a roof inspection next Tuesday.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'Caller asked about a roof inspection.',
        'action_items' => ['Schedule inspection for Tuesday'],
        'topics' => ['roof'],
        'next_steps' => ['Confirm time'],
        'sentiment' => 'neutral',
        'caller_intent' => 'schedule_service',
    ];

    Http::fake([
        'llm-gateway.assemblyai.com/*' => Http::response([
            'error' => 'Your account does not have access to LLM Gateway.',
        ], 401),
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'llm-gateway.assemblyai.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    $transcript->refresh();
    expect($transcript->summary)->toBe('Caller asked about a roof inspection.');
    expect($transcript->summary_model)->toBe('gpt-4o');
    expect($transcript->summarized_at)->not->toBeNull();
});

it('normalizes hallucinated month names using weekday ordinal hints from transcript', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');

    $callLog = CallLog::create([
        'call_control_id' => 'cc-date-fix',
        'direction' => 'incoming',
        'from_number' => '+15551234567',
        'to_number' => '+12247354200',
        'status' => 'completed',
        'created_at' => '2026-06-29 12:00:00',
        'updated_at' => '2026-06-29 12:00:00',
    ]);

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'Speaker A: I can pencil you in for Thursday the 9th. Speaker B: End of next week works for me.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $summary = [
        'summary' => 'They agreed to start around November 9th after prep work.',
        'action_items' => ['Pencil in work for November 9th'],
        'topics' => ['schedule'],
        'next_steps' => ['Start around November 9th'],
        'sentiment' => 'neutral',
        'caller_intent' => 'schedule_service',
        'recording_has_no_message' => false,
    ];

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($summary)],
            ]],
        ], 200),
    ]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    $transcript->refresh();

    expect($transcript->summary)->toContain('July 9th')
        ->and($transcript->summary)->not->toContain('November 9th')
        ->and($transcript->action_items[0])->toContain('July 9th')
        ->and($transcript->next_steps[0])->toContain('July 9th');
});

it('purges expired call recordings, transcripts, and stored audio', function () {
    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/test.mp3', 'fake-audio');

    $expired = CallLog::create([
        'call_control_id' => 'cc-old',
        'direction' => 'incoming',
        'status' => 'completed',
        'recording_url' => '/storage/call-recordings/test.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/test.mp3',
        'purge_after' => now()->subDay(),
    ]);
    CallTranscript::create([
        'call_log_id' => $expired->id,
        'text' => 'old transcript',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $fresh = CallLog::create([
        'call_control_id' => 'cc-new',
        'direction' => 'incoming',
        'status' => 'completed',
        'recording_url' => '/storage/keep.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/keep.mp3',
        'purge_after' => now()->addDays(30),
    ]);

    (new PurgeOldCallRecordings())->handle();

    $expired->refresh();
    expect($expired->recording_url)->toBeNull();
    expect($expired->recording_path)->toBeNull();
    expect($expired->purge_after)->toBeNull();
    expect(CallTranscript::where('call_log_id', $expired->id)->exists())->toBeFalse();
    expect(Storage::disk('local')->exists('public/call-recordings/test.mp3'))->toBeFalse();

    $fresh->refresh();
    expect($fresh->recording_url)->toBe('/storage/keep.mp3');
});

it('pins the agent label from speaker_map even when the agent speaks first on an outbound call', function () {
    // Regression for call 1326: on a mono-recorded outbound call the Hive
    // agent (Patryk) was the FIRST diarized speaker, so the "recipient picks
    // up first" heuristic mislabeled him as the customer. The content-based
    // speaker_map must override that.
    \DB::table('users')->insert([
        ['id' => 901, 'name' => 'Patryk Szady', 'first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2247354200'],
        ['id' => 902, 'name' => 'Richard Egger', 'first_name' => 'Richard', 'last_name' => 'Egger', 'cell_phone' => '3097815746'],
    ]);

    $callLog = CallLog::create([
        'call_control_id' => 'cc-1326',
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+13097815746',
        'status' => 'completed',
        'metadata' => ['user_phone' => '+12247354200'],
    ]);

    // Speaker A (the agent) speaks first — the order heuristic alone would
    // call A the customer.
    $segments = [
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 0.0, 'end' => 2.0, 'text' => 'Good afternoon, Patryk. I just thought I would touch base about the shed.'],
        ['speaker' => 'Speaker B', 'speaker_id' => 'B', 'start' => 2.0, 'end' => 4.0, 'text' => 'Great, thanks for following up.'],
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 4.0, 'end' => 6.0, 'text' => "I'll send you an invoice for the bathrooms tonight."],
    ];

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'transcript',
        'segments' => $segments,
        'speaker_map' => ['A' => 'Patryk Szady', 'B' => 'Richard Egger'],
        'status' => CallTranscript::STATUS_READY,
    ]);

    $map = $transcript->speakerLabelMap($callLog);

    expect($map['Speaker A'])->toBe('Patryk');
    expect($map['Speaker B'])->toBe('Richard');
});

it('labels voicemail and the caller from speaker_map on an inbound voicemail', function () {
    \DB::table('users')->insert([
        ['id' => 911, 'name' => 'Zora Spahiya', 'first_name' => 'Zora', 'last_name' => 'Spahiya', 'cell_phone' => '8474773080'],
    ]);

    $callLog = CallLog::create([
        'call_control_id' => 'cc-1327',
        'direction' => 'incoming',
        'from_number' => '+18474773080',
        'to_number' => '+12247354200',
        'caller_name' => 'Zora Spahiya',
        'has_voicemail' => true,
        'status' => 'completed',
    ]);

    $segments = [
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 0.0, 'end' => 3.0, 'text' => 'GS construction is not available right now. Press 1 to redial.'],
        ['speaker' => 'Speaker B', 'speaker_id' => 'B', 'start' => 3.0, 'end' => 6.0, 'text' => 'Hi, this is Zora. Returning your call.'],
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 6.0, 'end' => 7.0, 'text' => 'Okay, thank you, bye.'],
    ];

    $transcript = CallTranscript::create([
        'call_log_id' => $callLog->id,
        'engine' => 'assemblyai',
        'language' => 'en',
        'text' => 'transcript',
        'segments' => $segments,
        'speaker_map' => ['B' => 'Zora Spahiya'],
        'status' => CallTranscript::STATUS_READY,
    ]);

    $map = $transcript->speakerLabelMap($callLog);

    // The IVR speaker is overlaid as Voicemail; the human is the caller.
    expect($map['Speaker A'])->toBe('Voicemail');
    expect($map['Speaker B'])->toBe('Zora');
});

it('identifies speaker roles via the AssemblyAI LLM Gateway by default', function () {
    config()->set('call_recording.transcription.speaker_identification_driver', 'assemblyai');
    config()->set('call_recording.summarization.assemblyai_model', 'claude-sonnet-4-6');
    config()->set('services.assemblyai.api_key', 'test-aai-key');

    \DB::table('users')->insert([
        ['id' => 921, 'name' => 'Patryk Szady', 'first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2247354200'],
        ['id' => 922, 'name' => 'Richard Egger', 'first_name' => 'Richard', 'last_name' => 'Egger', 'cell_phone' => '3097815746'],
    ]);

    $callLog = CallLog::create([
        'call_control_id' => 'cc-spk-aai',
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+13097815746',
        'status' => 'completed',
        'metadata' => ['user_phone' => '+12247354200'],
    ]);

    $segments = [
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 0.0, 'end' => 2.0, 'text' => "I'll send you an invoice for the bathrooms tonight."],
        ['speaker' => 'Speaker B', 'speaker_id' => 'B', 'start' => 2.0, 'end' => 4.0, 'text' => 'Great, thanks for following up on my project.'],
    ];

    Http::fake([
        'llm-gateway.assemblyai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'speakers' => [
                        ['label' => 'A', 'role' => 'agent'],
                        ['label' => 'B', 'role' => 'other'],
                    ],
                ])],
            ]],
        ], 200),
    ]);

    $job = new TranscribeCallRecording($callLog->id);
    $method = new ReflectionMethod($job, 'identifySpeakers');
    $method->setAccessible(true);
    $map = $method->invoke($job, $segments, $callLog);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'llm-gateway.assemblyai.com')
        && $request['model'] === 'claude-sonnet-4-6');

    expect($map)->toBe(['A' => 'Patryk Szady', 'B' => 'Richard Egger']);
});

it('falls back to OpenAI for speaker roles when the AssemblyAI LLM Gateway is not accessible', function () {
    config()->set('call_recording.transcription.speaker_identification_driver', 'assemblyai');
    config()->set('services.assemblyai.api_key', 'test-aai-key');
    config()->set('services.openai.api_key', 'test-openai-key');

    \DB::table('users')->insert([
        ['id' => 931, 'name' => 'Patryk Szady', 'first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2247354200'],
        ['id' => 932, 'name' => 'Richard Egger', 'first_name' => 'Richard', 'last_name' => 'Egger', 'cell_phone' => '3097815746'],
    ]);

    $callLog = CallLog::create([
        'call_control_id' => 'cc-spk-fallback',
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+13097815746',
        'status' => 'completed',
        'metadata' => ['user_phone' => '+12247354200'],
    ]);

    $segments = [
        ['speaker' => 'Speaker A', 'speaker_id' => 'A', 'start' => 0.0, 'end' => 2.0, 'text' => "I'll send you an invoice for the bathrooms tonight."],
        ['speaker' => 'Speaker B', 'speaker_id' => 'B', 'start' => 2.0, 'end' => 4.0, 'text' => 'Great, thanks for following up on my project.'],
    ];

    Http::fake([
        'llm-gateway.assemblyai.com/*' => Http::response([
            'error' => 'Your account does not have access to LLM Gateway.',
        ], 401),
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'speakers' => [
                        ['label' => 'A', 'role' => 'agent'],
                        ['label' => 'B', 'role' => 'other'],
                    ],
                ])],
            ]],
        ], 200),
    ]);

    $job = new TranscribeCallRecording($callLog->id);
    $method = new ReflectionMethod($job, 'identifySpeakers');
    $method->setAccessible(true);
    $map = $method->invoke($job, $segments, $callLog);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'llm-gateway.assemblyai.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

    expect($map)->toBe(['A' => 'Patryk Szady', 'B' => 'Richard Egger']);
});

it('purges only the given recordings and their transcripts via the command', function () {
    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/junk.mp3', 'fake-audio');
    Storage::disk('local')->put('public/call-recordings/keep.mp3', 'real-audio');

    $junk = CallLog::create([
        'call_control_id' => 'cc-junk',
        'direction' => 'incoming',
        'status' => 'completed',
        'has_voicemail' => true,
        'recording_url' => '/storage/call-recordings/junk.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/junk.mp3',
    ]);
    CallTranscript::create([
        'call_log_id' => $junk->id,
        'engine' => 'assemblyai',
        'text' => 'Speaker A: Ram.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $keep = CallLog::create([
        'call_control_id' => 'cc-keep',
        'direction' => 'incoming',
        'status' => 'completed',
        'recording_url' => '/storage/call-recordings/keep.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/keep.mp3',
    ]);
    CallTranscript::create([
        'call_log_id' => $keep->id,
        'engine' => 'assemblyai',
        'text' => 'Speaker A: Hi, this is a real conversation about the roof.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $this->artisan('calls:purge-junk-recordings', ['--ids' => (string) $junk->id, '--execute' => true])
        ->assertSuccessful();

    $junk->refresh();
    expect($junk->recording_url)->toBeNull();
    expect($junk->recording_path)->toBeNull();
    expect($junk->recording_disk)->toBeNull();
    expect($junk->has_voicemail)->toBeFalse();
    expect(Storage::disk('local')->exists('public/call-recordings/junk.mp3'))->toBeFalse();
    expect(CallTranscript::where('call_log_id', $junk->id)->exists())->toBeFalse();

    // The untargeted call is untouched.
    $keep->refresh();
    expect($keep->recording_url)->toBe('/storage/call-recordings/keep.mp3');
    expect(Storage::disk('local')->exists('public/call-recordings/keep.mp3'))->toBeTrue();
    expect(CallTranscript::where('call_log_id', $keep->id)->exists())->toBeTrue();
});

it('makes no changes in dry-run mode', function () {
    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/dry.mp3', 'fake-audio');

    $call = CallLog::create([
        'call_control_id' => 'cc-dry',
        'direction' => 'incoming',
        'status' => 'completed',
        'has_voicemail' => true,
        'recording_url' => '/storage/call-recordings/dry.mp3',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/dry.mp3',
    ]);
    CallTranscript::create([
        'call_log_id' => $call->id,
        'engine' => 'assemblyai',
        'text' => 'Speaker A: Ram.',
        'status' => CallTranscript::STATUS_READY,
    ]);

    $this->artisan('calls:purge-junk-recordings', ['--ids' => (string) $call->id])
        ->assertSuccessful();

    $call->refresh();
    expect($call->recording_path)->toBe('public/call-recordings/dry.mp3');
    expect(Storage::disk('local')->exists('public/call-recordings/dry.mp3'))->toBeTrue();
    expect(CallTranscript::where('call_log_id', $call->id)->exists())->toBeTrue();
});

it('is idempotent and skips calls that are already clean', function () {
    $clean = CallLog::create([
        'call_control_id' => 'cc-clean',
        'direction' => 'incoming',
        'status' => 'completed',
    ]);

    $this->artisan('calls:purge-junk-recordings', ['--ids' => (string) $clean->id, '--execute' => true])
        ->expectsOutputToContain('already clean: 1')
        ->assertSuccessful();
});

