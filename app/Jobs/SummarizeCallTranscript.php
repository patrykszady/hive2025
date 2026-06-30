<?php

namespace App\Jobs;

use App\Models\CallTranscript;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SummarizeCallTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $callTranscriptId)
    {
    }

    public function handle(): void
    {
        $transcript = CallTranscript::find($this->callTranscriptId);
        if (! $transcript || ! $transcript->text) {
            return;
        }

        $driver = config('call_recording.summarization.driver', 'assemblyai');
        $outputLang = config('call_recording.summarization.output_language', 'English');

        $callLog = $transcript->callLog;
        $direction = $callLog?->direction ?? 'unknown';
        $from = $callLog?->from_number ?? '';
        $to = $callLog?->to_number ?? '';
        $caller = $callLog?->caller_name ?? '';

        // Resolve the participants by real name and replace "Speaker A/B"
        // labels in the transcript so the LLM can reference people by name.
        $labelMap = $callLog ? $transcript->speakerLabelMap($callLog) : [];
        $labeledText = $callLog ? $transcript->labeledText($callLog) : (string) $transcript->text;

        $agentUser = $callLog?->agentUser();
        $otherUser = $callLog?->otherPartyUser();
        $agentLine = $agentUser
            ? trim(($agentUser->first_name ?? '') . ' ' . ($agentUser->last_name ?? '')) . ' (Hive staff)'
            : 'Unknown Hive staff';
        $otherLine = $otherUser
            ? trim(($otherUser->first_name ?? '') . ' ' . ($otherUser->last_name ?? ''))
            : ($caller ?: 'Unknown party');

        $participantsList = '';
        foreach (array_unique(array_values($labelMap)) as $name) {
            $participantsList .= "- {$name}\n";
        }
        if ($participantsList === '') {
            $participantsList = "- {$agentLine}\n- {$otherLine}\n";
        }

        $system = <<<PROMPT
You are an assistant that summarizes phone calls for a construction-management platform.
Always write the summary, action items, topics, and next steps in {$outputLang}, even if the call was in another language.
Refer to people by their real first names as listed in "Participants" — never use "Speaker A", "Speaker B", or generic placeholders.
"Voicemail" indicates the automated greeting / IVR prompt and should not be treated as a person.

Write a focused summary of 3-5 sentences: cover why the call happened, the key points discussed, and what was decided or agreed. Include the concrete outcomes (timelines, prices, who is doing what) but stay factual and skip filler small talk. Do not pad to fill space, and do not balloon into a play-by-play of every exchange.

Action items are concrete commitments or tasks someone agreed to do (e.g. "Send the Climate Guard address to Richard", "Review the bathroom invoice"). Capture EVERY genuine action item in the call — do not cap or trim the list, and do not pad it with vague or implied tasks. Each item must be a short, specific, verb-first instruction naming who is responsible when known. If there are no real action items, return an empty list.

Date handling rules:
- Ground all date references using the call timestamp context.
- If a month is not explicitly spoken, do NOT invent one.
- For phrases like "Thursday the 9th" or "the week of the 13th", keep that wording unless the date can be unambiguously resolved from call timing.
- Do not convert a day-only mention into an incorrect month (example: do not turn "Thursday the 9th" into "November 9th" unless November is explicitly said).

Next steps are the expected upcoming sequence of events (which may overlap with action items). Topics are short noun phrases.

Set "recording_has_no_message" to true when the transcript contains NO substantive message and NO meaningful two-way human conversation. This includes: (a) the entire transcript is an automated voicemail/IVR greeting and/or system prompts (e.g. "You've reached my voicemail", "Please record your message after the tone", "I couldn't hear you, please try again"); OR (b) the recording is effectively empty, silent, just background noise, or only a stray word or two / unintelligible fragments with no discernible purpose or request (e.g. a single word like "Ram" or "Hello?" with nothing else). Set it to false whenever a participant actually left a coherent message or a real back-and-forth conversation occurred — even a short one — that conveys any request, information, or intent.

Do not invent information that is not in the transcript. Return JSON only, matching the provided schema.
PROMPT;

        $user = "Call metadata:\n"
            . "- Direction: {$direction}\n"
            . "- From: {$from}\n"
            . "- To: {$to}\n"
            . "- Hive staff on call: {$agentLine}\n"
            . "- Other party: {$otherLine}\n"
            . "- Detected language: " . ($transcript->language ?? 'unknown') . "\n\n"
            . "Participants:\n" . $participantsList . "\n"
            . "Transcript:\n" . $labeledText;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'action_items', 'topics', 'next_steps', 'sentiment', 'caller_intent', 'recording_has_no_message'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'action_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative', 'mixed']],
                'caller_intent' => ['type' => 'string'],
                'recording_has_no_message' => ['type' => 'boolean'],
            ],
        ];

        [$parsed, $usedModel] = $driver === 'openai'
            ? $this->summarizeWithOpenAI($transcript, $system, $user, $schema)
            : $this->summarizeWithAssemblyAI($transcript, $system, $user, $schema);

        if (! is_array($parsed)) {
            return;
        }

        $parsed = $this->normalizeInferredMonthMentions($parsed, $transcript, $callLog);

        $transcript->update([
            'summary_model' => $usedModel,
            'summary' => $parsed['summary'] ?? null,
            'action_items' => $parsed['action_items'] ?? [],
            'topics' => $parsed['topics'] ?? [],
            'next_steps' => $parsed['next_steps'] ?? [],
            'sentiment' => $parsed['sentiment'] ?? null,
            'caller_intent' => $parsed['caller_intent'] ?? null,
            'summarized_at' => now(),
        ]);

        // If the recording turned out to contain no real message or conversation
        // (e.g. an outbound call that only reached the target's voicemail
        // greeting), discard the audio + transcript so it doesn't clutter the
        // call list with a meaningless "voicemail".
        if (($parsed['recording_has_no_message'] ?? false) === true && $callLog) {
            Log::channel('telnyx')->info('Discarding recording flagged as no-message voicemail after summarization', [
                'call_log_id' => $callLog->id,
                'direction' => $callLog->direction,
                'transcript_id' => $transcript->id,
            ]);

            $callLog->purgeRecording();
        }
    }

    /**
     * Correct month/day hallucinations in LLM output when transcript contains
     * explicit weekday+ordinal hints (e.g. "Thursday the 9th").
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    protected function normalizeInferredMonthMentions(array $parsed, CallTranscript $transcript, $callLog): array
    {
        if (! $callLog || ! $callLog->created_at || ! is_string($transcript->text) || trim($transcript->text) === '') {
            return $parsed;
        }

        $resolvedByDay = $this->resolveTranscriptWeekdayOrdinalDates($transcript->text, $callLog->created_at);

        if ($resolvedByDay === []) {
            return $parsed;
        }

        if (isset($parsed['summary']) && is_string($parsed['summary'])) {
            $parsed['summary'] = $this->normalizeMonthMentionsInText($parsed['summary'], $resolvedByDay);
        }

        foreach (['action_items', 'next_steps'] as $listField) {
            if (! isset($parsed[$listField]) || ! is_array($parsed[$listField])) {
                continue;
            }

            $parsed[$listField] = collect($parsed[$listField])
                ->map(function ($item) use ($resolvedByDay) {
                    if (! is_string($item)) {
                        return $item;
                    }

                    return $this->normalizeMonthMentionsInText($item, $resolvedByDay);
                })
                ->all();
        }

        return $parsed;
    }

    /**
     * @return array<int, Carbon> Day-of-month => resolved date from call context.
     */
    protected function resolveTranscriptWeekdayOrdinalDates(string $transcriptText, Carbon $callCreatedAt): array
    {
        $pattern = '/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+the\s+(\d{1,2})(?:st|nd|rd|th)?\b/i';

        if (! preg_match_all($pattern, $transcriptText, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $resolved = [];

        foreach ($matches as $match) {
            $weekday = strtolower((string) ($match[1] ?? ''));
            $day = (int) ($match[2] ?? 0);

            if ($day < 1 || $day > 31) {
                continue;
            }

            $candidate = $this->resolveNextWeekdayDayFromContext($callCreatedAt, $weekday, $day);
            if ($candidate) {
                $resolved[$day] = $candidate;
            }
        }

        return $resolved;
    }

    protected function resolveNextWeekdayDayFromContext(Carbon $reference, string $weekday, int $day): ?Carbon
    {
        $cursor = $reference->copy()->startOfDay();

        for ($i = 0; $i < 14; $i++) {
            $candidate = $cursor->copy()->addMonthsNoOverflow($i)->startOfMonth();

            if ($day > $candidate->daysInMonth) {
                continue;
            }

            $candidate = $candidate->copy()->day($day);

            if ($candidate->lt($reference->copy()->startOfDay())) {
                continue;
            }

            if (strtolower($candidate->englishDayOfWeek) === $weekday) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, Carbon>  $resolvedByDay
     */
    protected function normalizeMonthMentionsInText(string $text, array $resolvedByDay): string
    {
        $months = [
            'january' => 1,
            'february' => 2,
            'march' => 3,
            'april' => 4,
            'may' => 5,
            'june' => 6,
            'july' => 7,
            'august' => 8,
            'september' => 9,
            'october' => 10,
            'november' => 11,
            'december' => 12,
        ];

        return (string) preg_replace_callback(
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(st|nd|rd|th)?\b/i',
            function (array $match) use ($resolvedByDay, $months): string {
                $monthName = strtolower((string) ($match[1] ?? ''));
                $day = (int) ($match[2] ?? 0);
                $suffix = (string) ($match[3] ?? $this->ordinalSuffix($day));

                if (! isset($months[$monthName]) || ! isset($resolvedByDay[$day])) {
                    return (string) ($match[0] ?? '');
                }

                $resolvedDate = $resolvedByDay[$day];
                $currentMonth = $months[$monthName];

                if ($currentMonth === (int) $resolvedDate->month) {
                    return (string) ($match[0] ?? '');
                }

                return $resolvedDate->format('F') . ' ' . $day . $suffix;
            },
            $text
        );
    }

    protected function ordinalSuffix(int $day): string
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Summarize through AssemblyAI's LLM Gateway (OpenAI-compatible chat
     * completions with structured outputs). Keeps the whole transcript ->
     * summary pipeline with a single vendor.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    protected function summarizeWithAssemblyAI(CallTranscript $transcript, string $system, string $user, array $schema): array
    {
        $apiKey = config('services.assemblyai.api_key');
        $model = config('call_recording.summarization.assemblyai_model', 'claude-sonnet-4-6');
        if (! $apiKey) {
            Log::warning('SummarizeCallTranscript: ASSEMBLYAI_API_KEY not configured');
            return [null, $model];
        }

        $response = Http::withHeaders(['authorization' => $apiKey])
            ->timeout(90)
            ->post('https://llm-gateway.assemblyai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'max_tokens' => 1500,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'call_summary',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'post_processing_steps' => [['type' => 'json-repair']],
            ]);

        if (! $response->successful()) {
            // The LLM Gateway is a paid add-on. When the account lacks access
            // (401/403) fall back to OpenAI so summaries still generate instead
            // of silently producing empty output.
            if (in_array($response->status(), [401, 403], true) && config('services.openai.api_key')) {
                Log::info('SummarizeCallTranscript: AssemblyAI LLM Gateway unavailable, falling back to OpenAI', [
                    'transcript_id' => $transcript->id,
                    'status' => $response->status(),
                ]);

                return $this->summarizeWithOpenAI($transcript, $system, $user, $schema);
            }

            Log::error('SummarizeCallTranscript: AssemblyAI LLM Gateway request failed', [
                'transcript_id' => $transcript->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            $this->fail(new \RuntimeException('AssemblyAI LLM Gateway request failed: ' . $response->status()));
            return [null, $model];
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            Log::error('SummarizeCallTranscript: invalid JSON from AssemblyAI LLM Gateway', [
                'transcript_id' => $transcript->id,
                'content' => $content,
            ]);
            return [null, $model];
        }

        return [$parsed, $model];
    }

    /**
     * Summarize through OpenAI chat completions (fallback driver).
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    protected function summarizeWithOpenAI(CallTranscript $transcript, string $system, string $user, array $schema): array
    {
        $apiKey = config('services.openai.api_key');
        $model = config('call_recording.summarization.model', 'gpt-4o');
        if (! $apiKey) {
            Log::warning('SummarizeCallTranscript: OPENAI_API_KEY not configured');
            return [null, $model];
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'call_summary',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('SummarizeCallTranscript: OpenAI request failed', [
                'transcript_id' => $transcript->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            $this->fail(new \RuntimeException('OpenAI request failed: ' . $response->status()));
            return [null, $model];
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            Log::error('SummarizeCallTranscript: invalid JSON from OpenAI', [
                'transcript_id' => $transcript->id,
                'content' => $content,
            ]);
            return [null, $model];
        }

        return [$parsed, $model];
    }
}
