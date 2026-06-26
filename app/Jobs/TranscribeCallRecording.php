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

        // WAV recordings (the configured default) are uploaded to AssemblyAI
        // byte-for-byte — lossless, full fidelity, no re-encode. The call below
        // only touches legacy MP3 recordings: it re-muxes them so browsers can
        // read duration (Telnyx MP3s often lack a Xing header) and down-mixes
        // to mono because Telnyx's stereo MP3s bleed audio between channels,
        // which breaks dual-channel diarization. For WAV this is a no-op.
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
            $message = $e->getMessage();
            $isUnsupportedAudio = $this->isUnsupportedAudioError($message);

            Log::channel('telnyx')->error('Transcription exception', [
                'call_log_id' => $callLog->id,
                'driver' => $driver,
                'error' => $message,
            ]);
            $transcript->update([
                'status' => $isUnsupportedAudio
                    ? CallTranscript::STATUS_UNSUPPORTED
                    : CallTranscript::STATUS_FAILED,
                'failure_reason' => 'exception: ' . substr($message, 0, 200),
            ]);

            // A file AssemblyAI cannot transcode is a permanent, terminal
            // failure. Re-throwing would burn the job's retries and the
            // `--retry-failed` scheduler would re-dispatch it every 5 minutes
            // forever. Swallow it — STATUS_UNSUPPORTED keeps it out of retries.
            if ($isUnsupportedAudio) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Whether a transcription error represents audio AssemblyAI can never
     * transcode (vs. a transient network/API hiccup worth retrying).
     */
    protected function isUnsupportedAudioError(string $message): bool
    {
        return stripos($message, 'Transcoding failed') !== false
            || stripos($message, 'may be unsupported') !== false;
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

        // Pin the speech model priority. Universal-3 Pro is the best
        // multilingual model and supports keyterms_prompt + custom_spelling;
        // Universal-2 is the documented fallback if U-3 Pro is unavailable.
        $speechModels = (array) config('call_recording.transcription.speech_models', ['universal-3-pro', 'universal-2']);
        if ($speechModels !== []) {
            $payload['speech_models'] = array_values($speechModels);
        }

        // Hint to the diarizer how many distinct speakers to expect. Per
        // AssemblyAI's guidance, `speakers_expected` should only be used when
        // the exact count is certain; otherwise a min/max range
        // (`speaker_options`) produces better separation. Our call volume mixes
        // 2-party calls, voicemails (IVR + caller), and the odd 3-party call,
        // so we default to the range and only pin an exact count when
        // explicitly configured.
        if ($payload['speaker_labels']) {
            $expectedSpeakers = config('call_recording.transcription.speakers_expected');
            if ($expectedSpeakers !== null && $expectedSpeakers !== '') {
                $payload['speakers_expected'] = (int) $expectedSpeakers;
            } else {
                $options = (array) config('call_recording.transcription.speaker_options', []);
                $min = isset($options['min_speakers_expected']) ? (int) $options['min_speakers_expected'] : null;
                $max = isset($options['max_speakers_expected']) ? (int) $options['max_speakers_expected'] : null;
                $speakerOptions = [];
                if ($min !== null && $min > 0) {
                    $speakerOptions['min_speakers_expected'] = $min;
                }
                if ($max !== null && $max > 0) {
                    $speakerOptions['max_speakers_expected'] = $max;
                }
                if ($speakerOptions !== []) {
                    $payload['speaker_options'] = $speakerOptions;
                }
            }
        }


        $forcedLanguage = config('call_recording.transcription.language');
        $allowedCodes = $this->parseAllowedLanguageCodes(
            (string) config('call_recording.transcription.language_codes', 'en,pl')
        );

        if ($forcedLanguage) {
            $payload['language_code'] = $forcedLanguage;
        } elseif (count($allowedCodes) >= 2 && in_array('en', $allowedCodes, true)) {
            // Code-switching: handle a single call that mixes languages
            // (e.g. English + Polish). `language_codes` is AssemblyAI's
            // multilingual path and is mutually exclusive with
            // `language_detection` / `language_detection_options`.
            $payload['language_codes'] = $allowedCodes;
        } elseif (count($allowedCodes) === 1) {
            $payload['language_code'] = $allowedCodes[0];
        } else {
            // No usable allow-list: fall back to single-language auto-detection.
            $payload['language_detection'] = true;
        }

        // Bias recognition toward known proper nouns (caller name, Hive staff,
        // contractors) so non-English names like "Grzegorz Szady" aren't
        // transcribed phonetically. keyterms_prompt is the Universal-2/3 Pro
        // replacement for the legacy word_boost and supports multi-word
        // phrases (max 6 words, up to 200/1000 entries).
        $keyterms = $this->buildKeytermsPrompt($callLog);
        if ($keyterms !== []) {
            $payload['keyterms_prompt'] = array_slice($keyterms, 0, 200);
        }

        // Hard rewrites for proper nouns AAI consistently mishears (e.g.
        // "Vady" → "Szady"). Combines a per-call set built from the caller +
        // agent names with a global config table maintained in
        // config/call_recording.php → name_corrections.
        $customSpelling = $this->buildCustomSpelling($callLog);
        if ($customSpelling !== []) {
            $payload['custom_spelling'] = $customSpelling;
        }

        // AssemblyAI Audio Intelligence add-ons. These run inline with the
        // transcription and surface structured data we'd otherwise have to
        // ask an LLM to infer: named entities (addresses, dates, dollar
        // amounts), key highlights, auto chapters, IAB topics, and per-
        // utterance sentiment. Stored on the transcript's `intelligence`
        // column. Note: sentiment_analysis / entity_detection are not
        // available alongside non-English code-switching on every model, so
        // AssemblyAI simply omits them from the response when unsupported —
        // we defensively read whatever comes back.
        $intelligence = (array) config('call_recording.transcription.intelligence', []);
        foreach (['entity_detection', 'auto_highlights', 'auto_chapters', 'iab_categories', 'sentiment_analysis'] as $feature) {
            if (! empty($intelligence[$feature])) {
                $payload[$feature] = true;
            }
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

        if ($this->containsOnlyVoicemailGreeting($segments, $text)) {
            $segments = [];
            $text = '';
        }

        // Collect any Audio Intelligence outputs AssemblyAI returned.
        $intelligence = $this->extractIntelligence($body);

        // Decide which diarized speaker is the Hive agent vs. the external
        // party from the conversation content (see identifySpeakersWithOpenAI).
        // Enrichment only — a failure here must not fail the transcription, so
        // it's wrapped and best-effort.
        $speakerMap = [];
        if (config('call_recording.transcription.speaker_identification', true) && $segments !== []) {
            try {
                $speakerMap = $this->identifySpeakersWithOpenAI($segments, $callLog);
            } catch (\Throwable $e) {
                Log::channel('telnyx')->warning('Speaker identification failed', [
                    'call_log_id' => $callLog->id,
                    'assemblyai_id' => $transcriptId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $transcript->update([
            'engine' => 'assemblyai',
            'language' => $detectedLanguage,
            'text' => $text !== '' ? $text : null,
            'segments' => $segments ?: null,
            'speaker_map' => $speakerMap ?: null,
            'intelligence' => $intelligence ?: null,
            'status' => $text !== '' ? CallTranscript::STATUS_READY : CallTranscript::STATUS_EMPTY,
            'failure_reason' => $text !== '' ? null : 'empty_transcript',
        ]);

        if ($text === '') {
            $this->removeSilentRecordingArtifacts($callLog);
        }

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
            'speaker_map' => $speakerMap ?: null,
            'intelligence' => array_keys($intelligence),
        ]);
    }

    /**
     * Pull AssemblyAI Audio Intelligence outputs out of the transcript
     * response body. Only includes features that actually returned data so
     * the stored blob stays compact.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function extractIntelligence(array $body): array
    {
        $intelligence = [];

        if (! empty($body['entities']) && is_array($body['entities'])) {
            $intelligence['entities'] = $body['entities'];
        }
        if (! empty($body['chapters']) && is_array($body['chapters'])) {
            $intelligence['chapters'] = $body['chapters'];
        }
        if (
            isset($body['auto_highlights_result']['results'])
            && is_array($body['auto_highlights_result']['results'])
            && $body['auto_highlights_result']['results'] !== []
        ) {
            $intelligence['highlights'] = $body['auto_highlights_result']['results'];
        }
        if (
            isset($body['iab_categories_result']['summary'])
            && is_array($body['iab_categories_result']['summary'])
            && $body['iab_categories_result']['summary'] !== []
        ) {
            $intelligence['iab_categories'] = $body['iab_categories_result']['summary'];
        }
        if (! empty($body['sentiment_analysis_results']) && is_array($body['sentiment_analysis_results'])) {
            $intelligence['sentiment'] = $body['sentiment_analysis_results'];
        }

        return $intelligence;
    }

    /**
     * Identify which diarized speaker is the Hive agent and which is the
     * external party by asking an LLM to classify each speaker's ROLE from the
     * conversation content. The model only decides role (agent vs. other) —
     * the actual names come from our own call records, so it can neither
     * hallucinate nor swap names. Returns the raw-label => name mapping, e.g.
     * ["A" => "Richard Egger", "B" => "Patryk Szady"].
     *
     * Diarization on our mono recordings makes "who picks up first" unreliable
     * (the agent is sometimes the first labelled speaker, sometimes the
     * second), and being addressed by name ("Patryk?") is easily mistaken for
     * being that person — so a content-based role decision is the only robust
     * signal we have.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<string, string>
     */
    protected function identifySpeakersWithOpenAI(array $segments, CallLog $callLog): array
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || $segments === []) {
            return [];
        }

        $agent = $callLog->agentUser();
        $agentName = $agent ? trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) : '';

        $other = $callLog->otherPartyUser();
        $otherName = $other
            ? trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? ''))
            : trim((string) $callLog->caller_name);

        // Need at least one known name to map a role onto.
        if ($agentName === '' && $otherName === '') {
            return [];
        }

        $labels = [];
        $lines = [];
        foreach ($segments as $seg) {
            $label = (string) ($seg['speaker_id'] ?? '');
            $text = trim((string) ($seg['text'] ?? ''));
            if ($label === '' || $text === '') {
                continue;
            }
            $labels[$label] = true;
            $lines[] = '[' . $label . '] ' . $text;
        }
        $labels = array_keys($labels);
        if (count($labels) < 2) {
            // Single speaker — nothing to disambiguate.
            return [];
        }

        $direction = $callLog->direction === 'incoming'
            ? 'INBOUND — the external party called Hive; a Hive staff member answered.'
            : 'OUTBOUND — a Hive staff member dialled the external party.';

        $system = 'You label speakers in a recorded phone call for Hive, a construction-management '
            . 'company (also branded "GS Construction" / "GS Crew"). For every speaker label, decide '
            . 'whether that speaker is the Hive AGENT (company staff) or the OTHER party (an external '
            . 'customer, contractor, or vendor). Respond with strict JSON only.';

        $guidance = [
            'The Hive AGENT represents the company: quotes prices, sends invoices/estimates, talks '
                . 'about "our price", "we/us", scheduling crews, permits, contractor discounts.',
            'The OTHER party is the customer/contractor: asks about their project, timeline, cost, and '
                . 'reacts to what the agent proposes.',
            'CRITICAL: being ADDRESSED by a name does NOT mean the speaker has that name. If a speaker '
                . 'says "Patryk?" or "is Patryk there", they are talking TO Patryk and are NOT Patryk.',
            'A speaker who INTRODUCES themselves ("good afternoon, this is X") IS that person.',
        ];

        $user = "Call direction: {$direction}\n"
            . 'Hive agent (company staff): ' . ($agentName !== '' ? $agentName : '(name unknown)') . "\n"
            . 'External party: ' . ($otherName !== '' ? $otherName : '(name unknown)') . "\n\n"
            . "How to tell them apart:\n- " . implode("\n- ", $guidance) . "\n\n"
            . "Transcript (each line prefixed with its speaker label):\n"
            . implode("\n", $lines);

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('call_recording.summarization.model', 'gpt-4o'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'speaker_roles',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'speakers' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'properties' => [
                                            'label' => ['type' => 'string'],
                                            'role' => ['type' => 'string', 'enum' => ['agent', 'other']],
                                        ],
                                        'required' => ['label', 'role'],
                                    ],
                                ],
                            ],
                            'required' => ['speakers'],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('openai_speaker_id_http_' . $response->status() . ': ' . $response->body());
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true) : null;
        $speakers = is_array($parsed) ? ($parsed['speakers'] ?? []) : [];

        $map = [];
        foreach ($speakers as $entry) {
            $label = trim((string) ($entry['label'] ?? ''));
            $role = (string) ($entry['role'] ?? '');
            if ($label === '') {
                continue;
            }
            $name = $role === 'agent' ? $agentName : $otherName;
            if ($name !== '') {
                $map[$label] = $name;
            }
        }

        return $map;
    }

    /**
     * Build a keyterms_prompt list (Universal-2/3 Pro replacement for the
     * legacy word_boost) of proper nouns to bias transcription toward, based
     * on what we already know about the call. Multi-word phrases are kept
     * intact (up to 6 words per AssemblyAI's limit).
     *
     * @return array<int, string>
     */
    protected function buildKeytermsPrompt(CallLog $callLog): array
    {
        $phrases = [];
        $tokens = [];

        $register = function (?string $value) use (&$phrases, &$tokens): void {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            // Phrase (full name): only keep ≤6 words, AssemblyAI's max.
            $wordCount = count(preg_split('/\s+/', $value) ?: []);
            if ($wordCount >= 1 && $wordCount <= 6) {
                $phrases[$value] = true;
            }
            foreach (preg_split('/\s+/', $value) as $token) {
                $token = trim((string) $token);
                if ($token !== '' && mb_strlen($token) >= 2) {
                    $tokens[$token] = true;
                    foreach ($this->nicknameAliases($token) as $alias) {
                        $tokens[$alias] = true;
                    }
                }
            }
        };

        if ($callLog->caller_name) {
            $register($callLog->caller_name);
        }
        if ($callLog->contact_user_id) {
            $contact = \App\Models\User::find($callLog->contact_user_id);
            if ($contact) {
                $register((string) $contact->name);
            }
        }

        // Resolve the external party + the Hive agent by phone number so we
        // bias AssemblyAI toward their names (fixes mishears like
        // "Patryk" → "Patrick" or "Grzegorz" → "Gregor").
        $other = $callLog->otherPartyUser();
        if ($other) {
            $register(trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? '')));
        }
        $agent = $callLog->agentUser();
        if ($agent) {
            $register(trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')));
        }

        // Bias toward all Hive staff names so cross-references ("ask Greg",
        // "talk to Andzelina") transcribe correctly. Cached so we don't query
        // for every transcription.
        $staff = \Illuminate\Support\Facades\Cache::remember(
            'call_transcription.staff_names',
            now()->addHours(6),
            fn () => \App\Models\User::whereNotNull('primary_vendor_id')
                ->get(['first_name', 'last_name'])
                ->all()
        );
        foreach ($staff as $u) {
            $register(trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));
        }

        // Also pull in canonical names from the global custom_spelling
        // config (e.g. "Grzegorz", "Szady") so even calls without a known
        // caller still benefit from bias toward Hive's typical proper nouns.
        foreach ((array) config('call_recording.transcription.name_corrections', []) as $canonical => $_aliases) {
            $register((string) $canonical);
        }

        // Cast every entry to a string. PHP silently coerces numeric-string
        // array keys to integers, so a purely numeric token (e.g. a caller
        // name that is actually a phone number) would otherwise be emitted as
        // a JSON number — which AssemblyAI rejects with
        // "`keyterms_prompt` list must contain strings".
        return array_values(array_unique(array_map(
            'strval',
            array_merge(array_keys($phrases), array_keys($tokens)),
        )));
    }

    /**
     * Build the AssemblyAI `custom_spelling` payload (hard rewrites). Uses
     * the curated `name_corrections` table from config plus per-call
     * derivations: any nicknameAliases('Patryk') become rewrites for the
     * canonical "Patryk", etc.
     *
     * @return array<int, array{from: array<int, string>, to: string}>
     */
    protected function buildCustomSpelling(CallLog $callLog): array
    {
        $rules = [];

        $add = function (string $canonical, array $aliases) use (&$rules): void {
            $canonical = trim($canonical);
            if ($canonical === '') {
                return;
            }
            $froms = [];
            foreach ($aliases as $a) {
                $a = trim((string) $a);
                if ($a === '' || strcasecmp($a, $canonical) === 0) {
                    continue;
                }
                $froms[$a] = true;
            }
            if ($froms === []) {
                return;
            }
            $rules[$canonical] = array_values(array_unique(array_merge(
                $rules[$canonical] ?? [],
                array_keys($froms),
            )));
        };

        // 1) Curated config table — the place we add new mishears as we
        //    encounter them in production.
        foreach ((array) config('call_recording.transcription.name_corrections', []) as $canonical => $aliases) {
            if (is_array($aliases)) {
                $add((string) $canonical, $aliases);
            }
        }

        // 2) Per-call: if the caller / agent has a name with known phonetic
        //    confusions (e.g. "Patryk" mishearable as "Patrick"), enforce the
        //    correct spelling for this call. This is in addition to the
        //    config — contributes when the staff member's name isn't yet in
        //    the curated table.
        $registerName = function (?string $name) use ($add): void {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            foreach (preg_split('/\s+/', $name) as $token) {
                $token = trim((string) $token);
                if ($token === '' || mb_strlen($token) < 3) {
                    continue;
                }
                $aliases = array_merge(
                    $this->nicknameAliases($token),
                    $this->vocativeMishears($token),
                );
                if ($aliases !== []) {
                    $add($token, $aliases);
                }
            }
        };

        $other = $callLog->otherPartyUser();
        if ($other) {
            $registerName(trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? '')));
        }
        $agent = $callLog->agentUser();
        if ($agent) {
            $registerName(trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')));
        }
        if ($callLog->caller_name) {
            $registerName((string) $callLog->caller_name);
        }

        $payload = [];
        foreach ($rules as $to => $from) {
            $payload[] = ['from' => $from, 'to' => $to];
        }

        return $payload;
    }

    /**
     * Parse a CSV of language codes and validate AssemblyAI's constraint
     * that the list (when used for code-switching) must include `en`.
     *
     * @return array<int, string>
     */
    protected function parseAllowedLanguageCodes(string $csv): array
    {
        $codes = array_values(array_filter(array_map(
            fn ($c) => strtolower(trim((string) $c)),
            explode(',', $csv),
        )));

        return array_values(array_unique($codes));
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

        if ($this->containsOnlyVoicemailGreeting(is_array($body['segments'] ?? null) ? $body['segments'] : [], $text)) {
            $text = '';
            $body['segments'] = [];
        }

        $transcript->update([
            'engine' => 'openai-whisper',
            'language' => $detectedLanguage,
            'text' => $text !== '' ? $text : null,
            'segments' => $body['segments'] ?? null,
            'status' => $text !== '' ? CallTranscript::STATUS_READY : CallTranscript::STATUS_EMPTY,
            'failure_reason' => $text !== '' ? null : 'empty_transcript',
        ]);

        if ($text === '') {
            $this->removeSilentRecordingArtifacts($callLog);
        }

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

    /**
     * Detect voicemail-greeting-only audio (no caller speech).
     *
     * @param  array<int, array<string, mixed>>  $segments
     */
    protected function containsOnlyVoicemailGreeting(array $segments, string $text): bool
    {
        $normalizedFull = strtolower(trim($text));
        if ($normalizedFull === '') {
            return false;
        }

        $phrases = [
            'you have reached my voicemail',
            'you\'ve reached my voicemail',
            'unable to take your call',
            'leave a message',
            'at the tone',
            'get back to you as soon as i can',
            'thanks and have a great day',
        ];

        $voicemailHitCount = 0;
        foreach ($phrases as $phrase) {
            if (str_contains($normalizedFull, $phrase)) {
                $voicemailHitCount++;
            }
        }

        // Must strongly look like a voicemail greeting before suppressing.
        if ($voicemailHitCount < 2) {
            return false;
        }

        // If there are diarized segments, require exactly one speaking segment
        // to avoid suppressing real conversations that mention voicemail.
        $nonEmptySegmentCount = 0;
        foreach ($segments as $segment) {
            $segmentText = trim((string) ($segment['text'] ?? ''));
            if ($segmentText !== '') {
                $nonEmptySegmentCount++;
            }
        }

        return $nonEmptySegmentCount <= 1;
    }

    /**
     * Silent recordings should not be retained in UI/storage.
     */
    protected function removeSilentRecordingArtifacts(CallLog $callLog): void
    {
        if ($callLog->recording_disk && $callLog->recording_path) {
            try {
                $disk = Storage::disk($callLog->recording_disk);
                if ($disk->exists($callLog->recording_path)) {
                    $disk->delete($callLog->recording_path);
                }
            } catch (\Throwable $e) {
                Log::channel('telnyx')->warning('Failed deleting silent recording file', [
                    'call_log_id' => $callLog->id,
                    'recording_disk' => $callLog->recording_disk,
                    'recording_path' => $callLog->recording_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $callLog->update([
            'recording_url' => null,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_telnyx_id' => null,
            'has_voicemail' => false,
        ]);

        Log::channel('telnyx')->info('Removed silent recording from call log', [
            'call_log_id' => $callLog->id,
        ]);
    }
}
