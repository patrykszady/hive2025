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

        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            Log::warning('SummarizeCallTranscript: OPENAI_API_KEY not configured');
            return;
        }

        $model = config('call_recording.summarization.model', 'gpt-4o');
        $outputLang = config('call_recording.summarization.output_language', 'English');

        $callLog = $transcript->callLog;
        $direction = $callLog?->direction ?? 'unknown';
        $from = $callLog?->from_number ?? '';
        $to = $callLog?->to_number ?? '';
        $caller = $callLog?->caller_name ?? '';

        $system = <<<PROMPT
You are an assistant that summarizes phone calls for a construction-management platform.
Always write the summary, action items, topics, and next steps in {$outputLang}, even if the call was in another language.
Keep the summary concise and factual; do not invent information that is not in the transcript.
Return JSON only, matching the provided schema.
PROMPT;

        $user = "Call metadata:\n"
            . "- Direction: {$direction}\n"
            . "- From: {$from}\n"
            . "- To: {$to}\n"
            . "- Caller name: {$caller}\n"
            . "- Detected language: " . ($transcript->language ?? 'unknown') . "\n\n"
            . "Transcript:\n" . $transcript->text;

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
            return;
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            Log::error('SummarizeCallTranscript: invalid JSON from OpenAI', [
                'transcript_id' => $transcript->id,
                'content' => $content,
            ]);
            return;
        }

        $transcript->update([
            'summary_model' => $model,
            'summary' => $parsed['summary'] ?? null,
            'action_items' => $parsed['action_items'] ?? [],
            'topics' => $parsed['topics'] ?? [],
            'next_steps' => $parsed['next_steps'] ?? [],
            'sentiment' => $parsed['sentiment'] ?? null,
            'caller_intent' => $parsed['caller_intent'] ?? null,
            'summarized_at' => now(),
        ]);
    }
}
