<?php

use App\Jobs\PurgeOldCallRecordings;
use App\Jobs\SummarizeCallTranscript;
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
