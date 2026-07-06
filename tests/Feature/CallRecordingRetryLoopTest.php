<?php

use App\Http\Controllers\Api\TelnyxWebhookController;
use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('call_control_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('status')->nullable();
            $table->string('recording_disk')->nullable();
            $table->string('recording_path')->nullable();
            $table->string('language', 8)->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('call_transcripts')) {
        Schema::create('call_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id');
            $table->string('engine')->nullable();
            $table->string('language', 8)->nullable();
            $table->longText('text')->nullable();
            $table->json('segments')->nullable();
            $table->json('speaker_map')->nullable();
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->json('intelligence')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();
        });
    }

    config()->set('call_recording.transcription.enabled', true);
    config()->set('call_recording.summarization.enabled', false);
});

function makeRecordedCall(): CallLog
{
    return CallLog::create([
        'call_control_id' => 'cc-' . uniqid(),
        'direction' => 'incoming',
        'from_number' => '+15551234567',
        'to_number' => '+12247354200',
        'status' => 'completed',
        'recording_disk' => 'local',
        'recording_path' => 'recordings/' . uniqid() . '.wav',
    ]);
}

it('does not re-queue transcription for empty (silent) recordings', function () {
    $emptyCall = makeRecordedCall();
    CallTranscript::create([
        'call_log_id' => $emptyCall->id,
        'engine' => 'assemblyai',
        'status' => CallTranscript::STATUS_EMPTY,
        'failure_reason' => 'empty_transcript',
    ]);

    Queue::fake();

    Artisan::call('calls:process-recordings', ['--retry-failed' => true, '--queue' => true]);

    Queue::assertNotPushed(
        TranscribeCallRecording::class,
        fn (TranscribeCallRecording $job) => $job->callLogId === $emptyCall->id,
    );
});

it('still re-queues transcription for genuinely failed recordings', function () {
    $failedCall = makeRecordedCall();
    CallTranscript::create([
        'call_log_id' => $failedCall->id,
        'engine' => 'assemblyai',
        'status' => CallTranscript::STATUS_FAILED,
        'failure_reason' => 'exception: connection timed out',
    ]);

    Queue::fake();

    Artisan::call('calls:process-recordings', ['--retry-failed' => true, '--queue' => true]);

    Queue::assertPushed(
        TranscribeCallRecording::class,
        fn (TranscribeCallRecording $job) => $job->callLogId === $failedCall->id,
    );
});

it('attaches an idempotent command_id to Telnyx call control commands', function () {
    config()->set('services.telnyx.api_key', 'test-telnyx-key');

    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $controller = app(TelnyxWebhookController::class);
    $method = new ReflectionMethod($controller, 'sendCallCommand');
    $method->setAccessible(true);

    $ok = $method->invoke($controller, 'cc-abc-123', 'speak', ['payload' => 'hello']);

    expect($ok)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/calls/cc-abc-123/actions/speak')
            && ! empty($request['command_id']);
    });
});
