<?php

use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * English is pinned for transcription (the bilingual mode hurt English
 * calls), so a Polish call used to come back as gibberish tagged en_us —
 * call 2922 on 2026-10-29. AssemblyAI's own confidence now decides: below
 * the threshold the recording is transcribed once more in Polish and the
 * more confident pass is kept.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('call_control_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('caller_name')->nullable();
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
            $table->decimal('confidence', 4, 3)->nullable();
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
    config()->set('call_recording.transcription.driver', 'assemblyai');
    config()->set('call_recording.transcription.language', 'en');
    config()->set('call_recording.transcription.retry_language', 'pl');
    config()->set('call_recording.transcription.retry_below_confidence', 0.6);
    config()->set('call_recording.transcription.retry_below_words_per_minute', 45);
    config()->set('call_recording.transcription.speaker_identification', false);
    config()->set('call_recording.transcription.intelligence', ['entity_detection' => true]);
    config()->set('call_recording.transcription.poll_interval_seconds', 1);
    config()->set('call_recording.summarization.enabled', false);
    config()->set('services.assemblyai.api_key', 'test-key');

    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/polish.wav', 'fake-audio');
});

function polishCall(): CallLog
{
    return CallLog::create([
        'call_control_id' => 'cc-'.uniqid(),
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+18476683573',
        'status' => 'completed',
        'recording_disk' => 'local',
        'recording_path' => 'public/call-recordings/polish.wav',
    ]);
}

/**
 * AssemblyAI faked by URL: the upload, then one transcript id per POST and
 * each id's finished body.
 *
 * @param  array<string, array<string, mixed>>  $bodies  id => completed body
 */
function fakeAssemblyAI(array $bodies): void
{
    $ids = array_keys($bodies);
    $posts = 0;

    Http::fake(function ($request) use ($bodies, $ids, &$posts) {
        $url = $request->url();

        if (str_contains($url, '/v2/upload')) {
            return Http::response(['upload_url' => 'https://cdn.assemblyai.com/upload/abc'], 200);
        }

        if (str_ends_with($url, '/v2/transcript') && $request->method() === 'POST') {
            $id = $ids[$posts] ?? 'unexpected-'.$posts;
            $posts++;

            return Http::response(['id' => $id, 'status' => 'queued'], 200);
        }

        foreach ($bodies as $id => $body) {
            if (str_ends_with($url, '/v2/transcript/'.$id)) {
                return Http::response(['id' => $id, 'status' => 'completed'] + $body, 200);
            }
        }

        return Http::response(['error' => 'unexpected '.$url], 500);
    });
}

function utterances(string ...$lines): array
{
    return collect($lines)->map(fn (string $line, int $i) => [
        'speaker' => $i % 2 ? 'B' : 'A', 'text' => $line, 'start' => $i * 1000, 'end' => $i * 1000 + 900,
    ])->all();
}

it('transcribes a Polish call again in Polish when the English pass is not confident, and keeps the Polish one', function () {
    $callLog = polishCall();

    fakeAssemblyAI([
        // Call 2922 as AssemblyAI returned it: 273 s of Polish, 9 English words, sure of itself.
        'aai-en' => ['language_code' => 'en_us', 'confidence' => 0.893, 'audio_duration' => 273, 'text' => 'Hello. A water line. Okay. Dobra. Jinko. Derfield.', 'utterances' => utterances('Hello. A water line.', 'Okay. Dobra. Jinko. Derfield.')],
        'aai-pl' => ['language_code' => 'pl', 'confidence' => 0.81, 'audio_duration' => 273, 'text' => 'Halo. Wycena linii wodnej. Dobra. Dziękuję. Deerfield, Illinois.', 'utterances' => utterances('Halo. Wycena linii wodnej.', 'Dobra. Dziękuję. Deerfield, Illinois.')],
    ]);

    (new TranscribeCallRecording($callLog->id))->handle();

    $transcript = CallTranscript::where('call_log_id', $callLog->id)->firstOrFail();
    expect($transcript->language)->toBe('pl');
    expect((float) $transcript->confidence)->toBe(0.81);
    expect($transcript->text)->toContain('Dziękuję');
    expect($callLog->fresh()->language)->toBe('pl');

    $posts = collect(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/transcript')))->values();
    expect($posts)->toHaveCount(2);
    expect($posts[0][0]['language_code'])->toBe('en');
    expect($posts[1][0]['language_code'])->toBe('pl');
    // The English-only add-ons would make AssemblyAI refuse the Polish pass.
    expect($posts[0][0]['entity_detection'] ?? null)->toBeTrue();
    expect(array_key_exists('entity_detection', $posts[1][0]->data()))->toBeFalse();
});

it('leaves a confident English call alone — one pass, confidence stored', function () {
    $callLog = polishCall();

    fakeAssemblyAI([
        'aai-en' => ['language_code' => 'en_us', 'confidence' => 0.91, 'audio_duration' => 20, 'text' => str_repeat('The water line estimate is ready for the State Farm adjuster. ', 5), 'utterances' => utterances('The water line estimate is ready.', 'Okay, thanks.')],
    ]);

    (new TranscribeCallRecording($callLog->id))->handle();

    $transcript = CallTranscript::where('call_log_id', $callLog->id)->firstOrFail();
    expect($transcript->language)->toBe('en_us');
    expect((float) $transcript->confidence)->toBe(0.91);
    expect(collect(Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/transcript'))))->toHaveCount(1);
});

it('keeps the English pass when the Polish pass heard no more of the call', function () {
    $callLog = polishCall();

    // Hold music: little speech in either language — English stays.
    fakeAssemblyAI([
        'aai-en' => ['language_code' => 'en_us', 'confidence' => 0.45, 'audio_duration' => 120, 'text' => 'Mumbled English please hold.', 'utterances' => utterances('Mumbled English.', 'Please hold.')],
        'aai-pl' => ['language_code' => 'pl', 'confidence' => 0.30, 'audio_duration' => 120, 'text' => 'Nic.', 'utterances' => utterances('Nic.', 'Okej.')],
    ]);

    (new TranscribeCallRecording($callLog->id))->handle();

    $transcript = CallTranscript::where('call_log_id', $callLog->id)->firstOrFail();
    expect($transcript->language)->toBe('en_us');
    expect((float) $transcript->confidence)->toBe(0.45);
    expect($transcript->text)->toContain('Mumbled');
});

it('makes no second pass when the second language is switched off', function () {
    config()->set('call_recording.transcription.retry_language', null);
    $callLog = polishCall();

    fakeAssemblyAI([
        'aai-en' => ['language_code' => 'en_us', 'confidence' => 0.2, 'audio_duration' => 200, 'text' => 'Dobra.', 'utterances' => utterances('Dobra.', 'Ok.')],
    ]);

    (new TranscribeCallRecording($callLog->id))->handle();

    expect(collect(Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/transcript'))))->toHaveCount(1);
});

it('Polish words in a confident, dense English transcript are enough to look again', function () {
    $callLog = polishCall();

    fakeAssemblyAI([
        'aai-en' => ['language_code' => 'en_us', 'confidence' => 0.9, 'audio_duration' => 20, 'text' => str_repeat('dobra dziękuję proszę jutro okay yes sure thanks fine good ', 4), 'utterances' => utterances('dobra dziękuję proszę jutro', 'okay yes')],
        'aai-pl' => ['language_code' => 'pl', 'confidence' => 0.88, 'audio_duration' => 20, 'text' => str_repeat('dobra dziękuję proszę jutro okej tak jasne dzięki dobrze fajnie ', 4), 'utterances' => utterances('dobra dziękuję proszę jutro', 'okej tak')],
    ]);

    (new TranscribeCallRecording($callLog->id))->handle();

    expect(CallTranscript::where('call_log_id', $callLog->id)->value('language'))->toBe('pl');
});

