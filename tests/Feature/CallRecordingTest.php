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
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('summary_model')->nullable();
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->json('topics')->nullable();
            $table->json('next_steps')->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('caller_intent')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();
        });
    }
});

it('exposes the recording disclosure config defaults', function () {
    expect(config('call_recording.mode'))->toBe('auto');
    expect(config('call_recording.retention_days'))->toBe(180);
    expect(config('call_recording.disclosure.phrase'))->toBe('This call is recorded.');
    expect(config('call_recording.summarization.model'))->toBe('gpt-4o');
    expect(config('call_recording.summarization.driver'))->toBe('openai');
});

it('summarizes a transcript via OpenAI and stores structured fields', function () {
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
