<?php

namespace App\Services\Meetings;

use App\Models\MeetingRecording;
use App\Models\User;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transcribes an in-person meeting with AssemblyAI, the way call recordings
 * are (TranscribeCallRecording): same speech models and English/Polish
 * code-switching from config/call_recording.php, speaker labels on, and the
 * project's people as key terms so names come out spelled right. Unlike a
 * call it never waits in a worker: submit() hands the audio over and
 * fetch() is asked again later (CheckMeetingTranscript) until it is done.
 */
class MeetingTranscriber
{
    private const API = 'https://api.assemblyai.com/v2';

    /** Upload the audio and ask for its transcript; returns AssemblyAI's transcript id. */
    public function submit(MeetingRecording $recording, string $absolutePath): string
    {
        $apiKey = $this->apiKey();

        $upload = Http::withHeaders(['authorization' => $apiKey])
            ->timeout(300)
            ->withBody((string) file_get_contents($absolutePath), 'application/octet-stream')
            ->post(self::API.'/upload');

        if (! $upload->successful() || ! $upload->json('upload_url')) {
            throw new RuntimeException('assemblyai_upload_http_'.$upload->status().': '.mb_substr($upload->body(), 0, 300));
        }

        $payload = [
            'audio_url' => $upload->json('upload_url'),
            'speaker_labels' => true,
            // A meeting has two people or a crowd: a range, never an exact count.
            'speaker_options' => ['min_speakers_expected' => 1, 'max_speakers_expected' => 8],
            'punctuate' => true,
            'format_text' => true,
        ];

        $speechModels = (array) config('call_recording.transcription.speech_models', ['universal-3-pro', 'universal-2']);
        if ($speechModels !== []) {
            $payload['speech_models'] = array_values($speechModels);
        }

        $languages = array_values(array_filter(array_map('trim', explode(',', (string) config('call_recording.transcription.language_codes', 'en,pl')))));
        if ($forced = config('call_recording.transcription.language')) {
            $payload['language_code'] = $forced;
        } elseif (count($languages) >= 2 && in_array('en', $languages, true)) {
            $payload['language_codes'] = $languages;
        } elseif (count($languages) === 1) {
            $payload['language_code'] = $languages[0];
        } else {
            $payload['language_detection'] = true;
        }

        $keyterms = $this->keyterms($recording);
        if ($keyterms !== []) {
            $payload['keyterms_prompt'] = array_slice($keyterms, 0, 200);
        }

        $create = Http::withHeaders(['authorization' => $apiKey])
            ->timeout(30)
            ->post(self::API.'/transcript', $payload);

        $id = (string) $create->json('id');

        if (! $create->successful() || $id === '') {
            throw new RuntimeException('assemblyai_create_http_'.$create->status().': '.mb_substr($create->body(), 0, 300));
        }

        return $id;
    }

    /**
     * The finished transcript (status "completed" or "error"), or null while
     * AssemblyAI is still working on it.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $transcriptId): ?array
    {
        $response = Http::withHeaders(['authorization' => $this->apiKey()])
            ->timeout(30)
            ->get(self::API.'/transcript/'.$transcriptId);

        if (! $response->successful()) {
            throw new RuntimeException('assemblyai_poll_http_'.$response->status().': '.mb_substr($response->body(), 0, 300));
        }

        $body = (array) $response->json();

        return in_array($body['status'] ?? null, ['completed', 'error'], true) ? $body : null;
    }

    /**
     * Speaker-labelled lines from a finished transcript.
     *
     * @param  array<string, mixed>  $body
     * @return array{text: string, segments: list<array{speaker: string, start: ?float, end: ?float, text: string}>, language: ?string}
     */
    public static function linesFrom(array $body): array
    {
        $segments = [];

        foreach ((array) ($body['utterances'] ?? []) as $utterance) {
            $text = trim((string) ($utterance['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $segments[] = [
                'speaker' => 'Speaker '.($utterance['speaker'] ?? '?'),
                'start' => isset($utterance['start']) ? $utterance['start'] / 1000 : null,
                'end' => isset($utterance['end']) ? $utterance['end'] / 1000 : null,
                'text' => $text,
            ];
        }

        $text = $segments !== []
            ? implode("\n", array_map(fn (array $s) => $s['speaker'].': '.$s['text'], $segments))
            : trim((string) ($body['text'] ?? ''));

        return ['text' => $text, 'segments' => $segments, 'language' => $body['language_code'] ?? null];
    }

    /**
     * Names worth spelling right: whoever recorded it, the project's client
     * users, the Meet's invitees and the project's street.
     *
     * @return list<string>
     */
    protected function keyterms(MeetingRecording $recording): array
    {
        $recording->loadMissing(['recordedBy', 'project.client.users', 'task']);
        $terms = collect();

        $people = collect([$recording->recordedBy])
            ->merge($recording->project?->client?->users ?? [])
            ->merge(User::query()->whereIn('email', (array) data_get($recording->task?->options, 'meeting_participants', []))->get())
            ->filter();

        foreach ($people as $user) {
            $terms->push(ConversationPresenter::preferredUserDisplayName($user), trim($user->first_name.' '.$user->last_name));
        }

        if ($street = $recording->project?->address) {
            $terms->push($street);
        }

        return $terms
            ->map(fn ($term) => trim((string) $term))
            ->filter(fn (string $term) => $term !== '' && str_word_count($term) <= 6)
            ->unique()
            ->values()
            ->all();
    }

    protected function apiKey(): string
    {
        return (string) config('services.assemblyai.api_key') ?: throw new RuntimeException('AssemblyAI API key missing (services.assemblyai.api_key)');
    }
}
