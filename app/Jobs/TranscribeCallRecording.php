<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscribeCallRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $callLogId)
    {
    }

    public function handle(): void
    {
        $callLog = CallLog::find($this->callLogId);
        if (! $callLog || ! $callLog->recording_path || ! $callLog->recording_disk) {
            return;
        }

        if (! config('call_recording.transcription.enabled')) {
            return;
        }

        $disk = Storage::disk($callLog->recording_disk);
        if (! $disk->exists($callLog->recording_path)) {
            Log::channel('telnyx')->warning('Recording file missing on disk; cannot transcribe', [
                'call_log_id' => $callLog->id,
                'path' => $callLog->recording_path,
            ]);
            return;
        }

        $absolutePath = $disk->path($callLog->recording_path);

        // Re-mux the MP3 once so browsers can show its duration without
        // downloading the whole file (Telnyx MP3s often lack a Xing header).
        // Also down-mix to mono — Telnyx's stereo recordings bleed audio
        // between channels which breaks dual-channel diarization, so we
        // always feed AssemblyAI a single mixed track and rely on
        // content-based speaker_labels diarization.
        $this->ensureMp3HasDurationHeader($callLog, $absolutePath);

        $driver = config('call_recording.transcription.driver', 'assemblyai');

        $transcript = CallTranscript::firstOrCreate(
            ['call_log_id' => $callLog->id],
            ['engine' => $driver, 'status' => CallTranscript::STATUS_TRANSCRIBING],
        );
        $transcript->update([
            'engine' => $driver,
            'status' => CallTranscript::STATUS_TRANSCRIBING,
        ]);

        try {
            if ($driver === 'assemblyai') {
                $this->runAssemblyAI($callLog, $transcript, $absolutePath);
                return;
            }

            $this->runWhisper($callLog, $transcript, $absolutePath);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Transcription exception', [
                'call_log_id' => $callLog->id,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
            $transcript->update([
                'status' => CallTranscript::STATUS_FAILED,
                'failure_reason' => 'exception: ' . substr($e->getMessage(), 0, 200),
            ]);
            throw $e;
        }
    }

    /* =========================
     |  AssemblyAI (Universal-2)
     |  with speaker diarization
     |  =========================
     */

    protected function runAssemblyAI(CallLog $callLog, CallTranscript $transcript, string $absolutePath): void
    {
        $apiKey = config('services.assemblyai.api_key');
        if (! $apiKey) {
            throw new \RuntimeException('AssemblyAI API key missing (services.assemblyai.api_key)');
        }

        $uploadUrl = $this->assemblyAIUpload($apiKey, $absolutePath);

        // Telnyx dual-channel recordings on bridged calls bleed audio between
        // channels (both parties leak onto both sides), which makes
        // dual_channel diarization produce duplicate utterances. Always use
        // speaker_labels (AssemblyAI's content-based diarization) on a mono
        // mix instead — much cleaner.
        $payload = [
            'audio_url' => $uploadUrl,
            'speaker_labels' => (bool) config('call_recording.transcription.speaker_labels', true),
            'punctuate' => true,
            'format_text' => true,
        ];

        // Hint to the diarizer how many distinct speakers to expect. Most of
        // our calls are 2-party (caller + agent); without this hint Universal-2
        // sometimes collapses two similar-sounding voices (e.g. two adult
        // males) into one speaker. Set to null to let the model decide.
        $expectedSpeakers = config('call_recording.transcription.speakers_expected');
        if ($expectedSpeakers !== null && $payload['speaker_labels']) {
            $payload['speakers_expected'] = (int) $expectedSpeakers;
        }

        $forcedLanguage = config('call_recording.transcription.language');
        $boostWords = $this->buildWordBoost($callLog);

        if ($forcedLanguage) {
            $payload['language_code'] = $forcedLanguage;
        } elseif ($boostWords !== []) {
            // word_boost requires a fixed language_code; default to English
            // (US calls) when we have proper nouns to bias toward.
            $payload['language_code'] = 'en';
        } else {
            $payload['language_detection'] = true;
        }

        // Bias recognition toward known proper nouns (caller name) so non-English
        // names like "Grzegorz Szady" aren't transcribed phonetically as
        // "Gregory Vady".
        if ($boostWords !== [] && isset($payload['language_code'])) {
            $payload['word_boost'] = $boostWords;
            $payload['boost_param'] = 'high';
        }

        $createResp = Http::withHeaders(['authorization' => $apiKey])
            ->timeout(30)
            ->post('https://api.assemblyai.com/v2/transcript', $payload);

        if (! $createResp->successful()) {
            throw new \RuntimeException('assemblyai_create_http_' . $createResp->status() . ': ' . $createResp->body());
        }

        $transcriptId = (string) ($createResp->json('id') ?? '');
        if ($transcriptId === '') {
            throw new \RuntimeException('AssemblyAI did not return a transcript id');
        }

        $body = $this->assemblyAIPoll($apiKey, $transcriptId);

        $status = (string) ($body['status'] ?? '');
        if ($status === 'error') {
            throw new \RuntimeException('assemblyai_error: ' . ($body['error'] ?? 'unknown'));
        }
        if ($status !== 'completed') {
            throw new \RuntimeException('assemblyai_status_' . $status);
        }

        $utterances = is_array($body['utterances'] ?? null) ? $body['utterances'] : [];
        $segments = [];
        foreach ($utterances as $u) {
            $text = trim((string) ($u['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $rawSpeaker = $u['speaker'] ?? null;
            $segments[] = [
                'speaker' => 'Speaker ' . ((string) ($rawSpeaker ?? '?')),
                'speaker_id' => (string) ($rawSpeaker ?? ''),
                'start' => isset($u['start']) ? ((int) $u['start']) / 1000 : null,
                'end' => isset($u['end']) ? ((int) $u['end']) / 1000 : null,
                'text' => $text,
            ];
        }

        // Replace misheard self-introductions ("this is Gregory Vady") with the
        // known caller name from CNAM / contact records ("Grzegorz Szady").
        $segments = $this->applyKnownNameSubstitutions($callLog, $segments);

        $detectedLanguage = $body['language_code'] ?? $forcedLanguage ?? null;
        $text = $segments
            ? trim(implode("\n", array_map(fn ($s) => $s['speaker'] . ': ' . $s['text'], $segments)))
            : trim((string) ($body['text'] ?? ''));

        $transcript->update([
            'engine' => 'assemblyai',
            'language' => $detectedLanguage,
            'text' => $text !== '' ? $text : null,
            'segments' => $segments ?: null,
            'status' => $text !== '' ? CallTranscript::STATUS_READY : CallTranscript::STATUS_FAILED,
            'failure_reason' => $text !== '' ? null : 'empty_transcript',
        ]);

        if ($detectedLanguage) {
            $callLog->update(['language' => $detectedLanguage]);
        }

        Log::channel('telnyx')->info('AssemblyAI transcription complete', [
            'call_log_id' => $callLog->id,
            'transcript_id' => $transcript->id,
            'assemblyai_id' => $transcriptId,
            'language' => $detectedLanguage,
            'utterances' => count($segments),
            'chars' => strlen($text),
        ]);
    }

    /**
     * Build a list of proper nouns to boost during transcription, based on
     * what we already know about the call (caller name, contact name).
     * Returns deduped, non-empty tokens.
     *
     * @return array<int, string>
     */
    protected function buildWordBoost(CallLog $callLog): array
    {
        $sources = [];
        if ($callLog->caller_name) {
            $sources[] = (string) $callLog->caller_name;
        }
        if ($callLog->contact_user_id) {
            $contact = \App\Models\User::find($callLog->contact_user_id);
            if ($contact) {
                $sources[] = (string) $contact->name;
            }
        }

        // Resolve the external party + the Hive agent by phone number so we
        // bias AssemblyAI toward their names (fixes mishears like
        // "Dick" → "Nick" or "Patryk" → "Patrick / Peter").
        $other = $callLog->otherPartyUser();
        if ($other) {
            $sources[] = trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? ''));
        }
        $agent = $callLog->agentUser();
        if ($agent) {
            $sources[] = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? ''));
        }

        // Also bias toward all Hive staff first names so cross-references
        // ("ask Greg", "talk to Andzelina") transcribe correctly. Cached so
        // we don't query for every transcription.
        $staffNames = \Illuminate\Support\Facades\Cache::remember(
            'call_transcription.staff_names',
            now()->addHours(6),
            fn () => \App\Models\User::whereNotNull('primary_vendor_id')
                ->get(['first_name', 'last_name'])
                ->flatMap(fn ($u) => array_filter([$u->first_name, $u->last_name]))
                ->unique()
                ->values()
                ->all()
        );
        foreach ($staffNames as $n) {
            $sources[] = (string) $n;
        }

        $words = [];
        foreach ($sources as $src) {
            foreach (preg_split('/\s+/', trim($src)) as $token) {
                $token = trim((string) $token);
                if ($token === '' || mb_strlen($token) < 2) {
                    continue;
                }
                $words[$token] = true;
                $words[mb_convert_case($token, MB_CASE_TITLE)] = true;
                foreach ($this->nicknameAliases($token) as $alias) {
                    $words[$alias] = true;
                }
            }
        }

        return array_values(array_keys($words));
    }

    /**
     * Common English first-name nicknames so word_boost catches both forms
     * (e.g. caller listed as "Richard" but addressed as "Dick").
     *
     * @return array<int, string>
     */
    protected function nicknameAliases(string $name): array
    {
        static $map = [
            'Richard' => ['Rick', 'Dick', 'Rich', 'Richie'],
            'Robert' => ['Rob', 'Bob', 'Bobby', 'Robbie'],
            'William' => ['Will', 'Bill', 'Billy', 'Willy'],
            'James' => ['Jim', 'Jimmy', 'Jamie'],
            'John' => ['Johnny', 'Jack', 'Jon'],
            'Michael' => ['Mike', 'Mick', 'Mikey'],
            'Christopher' => ['Chris', 'Topher'],
            'Joseph' => ['Joe', 'Joey'],
            'Charles' => ['Charlie', 'Chuck'],
            'Thomas' => ['Tom', 'Tommy'],
            'Daniel' => ['Dan', 'Danny'],
            'Anthony' => ['Tony'],
            'Edward' => ['Ed', 'Eddie', 'Ted'],
            'Nicholas' => ['Nick', 'Nico'],
            'Andrew' => ['Andy', 'Drew'],
            'Matthew' => ['Matt', 'Matty'],
            'David' => ['Dave', 'Davey'],
            'Patrick' => ['Pat', 'Paddy'],
            'Patryk' => ['Patrick', 'Pat'],
            'Grzegorz' => ['Greg', 'Gregory'],
            'Elizabeth' => ['Liz', 'Beth', 'Lizzie', 'Betty'],
            'Katherine' => ['Kate', 'Katie', 'Kathy', 'Kat'],
            'Margaret' => ['Maggie', 'Meg', 'Peggy'],
        ];

        $key = mb_convert_case($name, MB_CASE_TITLE);

        return $map[$key] ?? [];
    }

    /**
     * Replace misheard self-introductions in segments with the known caller name.
     * Matches phrases like "this is X", "my name is X", "X X calling" and
     * substitutes the spoken name with the canonical one (e.g. "Gregory Vady"
     * → "Grzegorz Szady"). No-op if we have no caller_name on the CallLog.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    protected function applyKnownNameSubstitutions(CallLog $callLog, array $segments): array
    {
        $callerName = trim((string) $callLog->caller_name);
        if ($callerName === '') {
            $other = $callLog->otherPartyUser();
            if ($other) {
                $callerName = trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? ''));
            }
        }

        $agentName = '';
        $agent = $callLog->agentUser();
        if ($agent) {
            $agentName = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? ''));
        }

        if (($callerName === '' && $agentName === '') || $segments === []) {
            return $segments;
        }

        $callerFirst = $callerName !== '' ? explode(' ', $callerName)[0] : '';
        $agentFirst = $agentName !== '' ? explode(' ', $agentName)[0] : '';

        // Identify which raw speaker label is the Hive agent. We assume the
        // second distinct speaker is the agent (recipient/inbound caller
        // speaks first), matching the heuristic used in the UI.
        $order = [];
        foreach ($segments as $seg) {
            $sp = $seg['speaker'] ?? null;
            if ($sp !== null && ! in_array($sp, $order, true)) {
                $order[] = $sp;
            }
        }
        $agentSpeaker = $order[1] ?? null;

        $namePattern = '([A-Z][\p{L}\'\-]+(?:\s+[A-Z][\p{L}\'\-]+){0,2})';
        $selfIntroPatterns = [
            '/\b(this is)\s+' . $namePattern . '/u',
            '/\b(my name is)\s+' . $namePattern . '/u',
            '/\b(it\'s|its)\s+' . $namePattern . '\s+(calling|here)\b/iu',
            '/\b' . $namePattern . '\s+(calling|here)\b/u',
        ];
        $greetingPatterns = [
            '/\b(hello,?\s+|hi,?\s+|hey,?\s+)([A-Z][\p{L}\'\-]+)/iu',
            '/\b(good\s+(?:morning|afternoon|evening),?\s+)([A-Z][\p{L}\'\-]+)/iu',
        ];

        foreach ($segments as &$seg) {
            $text = (string) ($seg['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $rawSpeaker = $seg['speaker'] ?? null;
            $isAgentSpeaking = $agentSpeaker !== null && $rawSpeaker === $agentSpeaker;

            // Self-intro: speaker is referring to themselves.
            $selfName = $isAgentSpeaking ? $agentName : $callerName;
            // Greeting: speaker is addressing the OTHER party.
            $otherFirst = $isAgentSpeaking ? $callerFirst : $agentFirst;

            $original = $text;

            if ($selfName !== '') {
                foreach ($selfIntroPatterns as $i => $pattern) {
                    $text = preg_replace_callback($pattern, function ($m) use ($selfName, $i) {
                        if ($i === 3) {
                            return $selfName . ' ' . $m[2];
                        }
                        if ($i === 2) {
                            return $m[1] . ' ' . $selfName . ' ' . $m[3];
                        }
                        return $m[1] . ' ' . $selfName;
                    }, $text) ?? $text;
                }
            }

            if ($otherFirst !== '') {
                foreach ($greetingPatterns as $pattern) {
                    $text = preg_replace_callback($pattern, function ($m) use ($otherFirst) {
                        return $m[1] . $otherFirst;
                    }, $text) ?? $text;
                }
            }

            // Vocative use: speaker addresses the OTHER party by name mid-
            // sentence (e.g. "Nick, last thing..."). Replace any known
            // nickname/mishear of the other party's name with their canonical
            // first name.
            $targetFirst = $isAgentSpeaking ? $callerFirst : $agentFirst;
            if ($targetFirst !== '') {
                $vocativeTokens = $this->vocativeMishears($targetFirst);
                if ($vocativeTokens !== []) {
                    $alt = implode('|', array_map('preg_quote', $vocativeTokens));
                    $text = preg_replace_callback(
                        '/\b(' . $alt . ')(?=[,.!?\s])/iu',
                        fn ($m) => $targetFirst,
                        $text
                    ) ?? $text;
                }
            }

            if ($text !== $original) {
                $seg['text'] = $text;
            }
        }
        unset($seg);

        return $segments;
    }

    /**
     * Tokens that AssemblyAI commonly hears in place of the given first
     * name — used to repair mid-sentence vocative addresses. Combines our
     * nickname list with a small phonetic-confusion table.
     *
     * @return array<int, string>
     */
    protected function vocativeMishears(string $first): array
    {
        $first = mb_convert_case($first, MB_CASE_TITLE);

        // Phonetic confusions observed in real call transcripts. Conservative
        // by design — only add when the mishear is highly distinctive of the
        // target name (avoid common English words).
        static $confusions = [
            'Dick' => ['Nick', 'Mick', 'Pick'],
            'Rick' => ['Nick', 'Mick'],
            'Bob' => ['Bop', 'Bub'],
            'Bill' => ['Phil'],
            'Jim' => ['Gym'],
            'Tom' => ['Tum'],
            'Pat' => ['Pack'],
        ];

        $aliases = $this->nicknameAliases($first);
        $candidates = $aliases;

        // Pull confusions for the canonical first name AND for any of its
        // aliases (e.g. Richard → also include confusions of "Dick"/"Rick").
        foreach (array_merge([$first], $aliases) as $form) {
            foreach ($confusions[$form] ?? [] as $c) {
                $candidates[] = $c;
            }
        }

        // Don't substitute the canonical name with itself.
        return array_values(array_unique(array_filter(
            $candidates,
            fn ($c) => strcasecmp($c, $first) !== 0
        )));
    }

    protected function assemblyAIUpload(string $apiKey, string $absolutePath): string
    {
        $resp = Http::withHeaders([
            'authorization' => $apiKey,
            'content-type' => 'application/octet-stream',
        ])
            ->timeout(120)
            ->withBody(file_get_contents($absolutePath), 'application/octet-stream')
            ->post('https://api.assemblyai.com/v2/upload');

        if (! $resp->successful()) {
            throw new \RuntimeException('assemblyai_upload_http_' . $resp->status() . ': ' . $resp->body());
        }

        $uploadUrl = (string) ($resp->json('upload_url') ?? '');
        if ($uploadUrl === '') {
            throw new \RuntimeException('AssemblyAI upload returned no upload_url');
        }

        return $uploadUrl;
    }

    /**
     * Detect channel count via ffprobe; returns 1 if it can't be determined.
     */
    protected function audioChannels(string $absolutePath): int
    {
        $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            return 1;
        }
        $out = @shell_exec(
            $ffprobe . ' -v error -select_streams a:0 -show_entries stream=channels -of csv=p=0 '
            . escapeshellarg($absolutePath) . ' 2>/dev/null'
        );
        return max(1, (int) trim((string) $out));
    }

    /**
     * Re-mux the MP3 through ffmpeg so it gets a proper Xing/Info header,
     * which lets browsers report duration on `preload=metadata`. Telnyx MP3s
     * frequently ship without one. Idempotent: we mark the call once it's
     * been re-muxed so we don't repeat work.
     */
    protected function ensureMp3HasDurationHeader(CallLog $callLog, string $absolutePath): void
    {
        $metadata = is_array($callLog->metadata) ? $callLog->metadata : [];
        if (! empty($metadata['recording_mono_remuxed'])) {
            return;
        }

        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'mp3') {
            return;
        }

        $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '') {
            return;
        }

        $tmp = $absolutePath . '.remuxed.mp3';
        // -ac 1 down-mixes to mono so AssemblyAI sees one clean stream.
        $cmd = sprintf(
            '%s -y -i %s -ac 1 -map_metadata 0 -write_xing 1 %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($absolutePath),
            escapeshellarg($tmp),
        );
        @exec($cmd, $output, $code);

        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
            // Try to also stamp duration from ffprobe output in metadata.
            $duration = $this->probeDurationSeconds($tmp);

            if (@rename($tmp, $absolutePath)) {
                $metadata['recording_remuxed'] = true;
                $metadata['recording_mono_remuxed'] = true;
                if ($duration !== null) {
                    $metadata['recording_duration_seconds'] = $duration;
                }
                $callLog->update(['metadata' => $metadata]);
                return;
            }
        }

        @unlink($tmp);
        Log::channel('telnyx')->warning('ffmpeg mp3 remux failed', [
            'call_log_id' => $callLog->id,
            'exit_code' => $code ?? null,
            'output' => isset($output) ? implode("\n", array_slice($output, -3)) : null,
        ]);
    }

    protected function probeDurationSeconds(string $absolutePath): ?float
    {
        $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            return null;
        }
        $out = @shell_exec(
            $ffprobe . ' -v error -show_entries format=duration -of default=nw=1:nk=1 '
            . escapeshellarg($absolutePath) . ' 2>/dev/null'
        );
        $val = trim((string) $out);
        return $val !== '' ? (float) $val : null;
    }

    protected function assemblyAIPoll(string $apiKey, string $transcriptId): array
    {
        $intervalSec = max(1, (int) config('call_recording.transcription.poll_interval_seconds', 3));
        $timeoutSec = max(30, (int) config('call_recording.transcription.poll_timeout_seconds', 600));
        $deadline = time() + $timeoutSec;

        $url = 'https://api.assemblyai.com/v2/transcript/' . $transcriptId;

        while (time() < $deadline) {
            $resp = Http::withHeaders(['authorization' => $apiKey])->timeout(30)->get($url);
            if (! $resp->successful()) {
                throw new \RuntimeException('assemblyai_poll_http_' . $resp->status() . ': ' . $resp->body());
            }
            $body = (array) $resp->json();
            $status = (string) ($body['status'] ?? '');
            if ($status === 'completed' || $status === 'error') {
                return $body;
            }
            sleep($intervalSec);
        }

        throw new \RuntimeException('assemblyai_poll_timeout');
    }

    /* =================================
     |  OpenAI Whisper (fallback driver)
     |  No diarization.
     |  =================================
     */

    protected function runWhisper(CallLog $callLog, CallTranscript $transcript, string $absolutePath): void
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new \RuntimeException('OpenAI API key missing');
        }

        $model = config('call_recording.transcription.model', 'whisper-1');
        $language = config('call_recording.transcription.language');

        $payload = ['model' => $model, 'response_format' => 'verbose_json'];
        if ($language) {
            $payload['language'] = $language;
        }

        $resp = Http::withToken($apiKey)
            ->timeout(120)
            ->attach('file', file_get_contents($absolutePath), basename($absolutePath))
            ->post('https://api.openai.com/v1/audio/transcriptions', $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException('whisper_http_' . $resp->status() . ': ' . $resp->body());
        }

        $body = (array) $resp->json();
        $text = trim((string) ($body['text'] ?? ''));
        $detectedLanguage = $body['language'] ?? null;

        $transcript->update([
            'engine' => 'openai-whisper',
            'language' => $detectedLanguage,
            'text' => $text !== '' ? $text : null,
            'segments' => $body['segments'] ?? null,
            'status' => $text !== '' ? CallTranscript::STATUS_READY : CallTranscript::STATUS_FAILED,
            'failure_reason' => $text !== '' ? null : 'empty_transcript',
        ]);

        if ($detectedLanguage) {
            $callLog->update(['language' => $detectedLanguage]);
        }

        Log::channel('telnyx')->info('Whisper transcription complete', [
            'call_log_id' => $callLog->id,
            'transcript_id' => $transcript->id,
            'language' => $detectedLanguage,
            'chars' => strlen($text),
        ]);
    }
}
