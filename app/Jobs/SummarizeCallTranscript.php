<?php

namespace App\Jobs;

use App\Models\CallTranscript;
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

Next steps are the expected upcoming sequence of events (which may overlap with action items). Topics are short noun phrases.

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
            'required' => ['summary', 'action_items', 'topics', 'next_steps', 'sentiment', 'caller_intent'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'action_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative', 'mixed']],
                'caller_intent' => ['type' => 'string'],
            ],
        ];

        [$parsed, $usedModel] = $driver === 'openai'
            ? $this->summarizeWithOpenAI($transcript, $system, $user, $schema)
            : $this->summarizeWithAssemblyAI($transcript, $system, $user, $schema);

        if (! is_array($parsed)) {
            return;
        }

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
                Log::warning('SummarizeCallTranscript: AssemblyAI LLM Gateway unavailable, falling back to OpenAI', [
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
