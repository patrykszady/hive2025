<?php

namespace App\Services\Meetings;

use App\Models\MeetingRecording;
use App\Models\User;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Summarizes an in-person meeting's transcript the way a call's is
 * (SummarizeCallTranscript): the same driver and models from
 * config/call_recording.php (AssemblyAI's LLM gateway, OpenAI when that
 * account lacks it), the same action-item rules — so the planner reads a
 * meeting's commitments exactly like a call's — with a prompt for a meeting
 * walked on site rather than a phone call.
 */
class MeetingSummarizer
{
    /**
     * @return array{0: array{summary: string, action_items: list<string>, topics: list<string>, next_steps: list<string>, recording_has_no_message: bool}, 1: string}
     */
    public function summarize(MeetingRecording $recording): array
    {
        $recording->loadMissing(['recordedBy', 'project.client.users', 'task']);

        $recorder = $recording->recordedBy ? ConversationPresenter::preferredUserDisplayName($recording->recordedBy) : 'Hive staff';
        $people = collect($recording->project?->client?->users ?? [])
            ->merge(User::query()->whereIn('email', (array) data_get($recording->task?->options, 'meeting_participants', []))->get())
            ->filter(fn (User $user) => $user->id !== $recording->recorded_by_user_id)
            ->unique('id')
            ->map(fn (User $user) => '- '.ConversationPresenter::preferredUserDisplayName($user))
            ->implode("\n");
        $outputLanguage = config('call_recording.summarization.output_language', 'English');
        $when = ($recording->started_at ?? $recording->created_at)->timezone('America/Chicago');

        $system = <<<PROMPT
You are an assistant that summarizes in-person meetings for a construction company — walkthroughs, site visits and kitchen-table meetings with homeowners, subcontractors and the crew — recorded on a microphone worn by {$recorder}.
Always write the summary, action items, topics and next steps in {$outputLanguage}, even if the meeting was in another language.
The transcript labels voices "Speaker A", "Speaker B" and so on. {$recorder} wears the microphone, so is usually the clearest and most frequent speaker. Refer to people by their real first names when the conversation makes clear who is speaking (from "Participants" or from what they say); otherwise say "the homeowner", "the sub" or similar — never "Speaker A".

Write a focused summary of 3-6 sentences: what the meeting was for, the key points discussed, and what was decided or agreed — concrete outcomes (scope, selections, prices, dates, who is doing what). Skip small talk and do not narrate every exchange.

Action items are concrete commitments or tasks someone agreed to do (e.g. "Send the revised quote to Erin", "Order the 36-inch vanity"). Capture EVERY genuine action item — do not cap the list and do not pad it with vague or implied tasks. Each item is a short, specific, verb-first instruction naming who is responsible when known, written as "Name: task" when there is an owner. If there are no real action items, return an empty list.

Date handling: ground dates on the meeting time given below. If a month is not said, do not invent one; keep phrases like "Thursday the 9th" as said unless they resolve unambiguously.

Next steps are the expected upcoming sequence of events. Topics are short noun phrases.

Set "recording_has_no_message" to true only when there is no meaningful conversation at all — silence, background noise, or a few unintelligible words. Do not invent information that is not in the transcript. Return JSON only, matching the schema.
PROMPT;

        $user = "Meeting:\n"
            .'- When: '.$when->format('l, F j, Y g:i A')." (America/Chicago)\n"
            .'- Project: '.($recording->project?->name ?? 'not given').($recording->project?->address ? ' — '.$recording->project->address : '')."\n"
            .'- Recorded by (wearing the mic): '.$recorder."\n"
            .'- Detected language: '.($recording->language ?? 'unknown')."\n\n"
            ."Participants (who may be in it):\n- {$recorder} (Hive staff)\n".($people !== '' ? $people."\n" : '')
            ."\nTranscript:\n".$recording->transcript;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'action_items', 'topics', 'next_steps', 'recording_has_no_message'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'action_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recording_has_no_message' => ['type' => 'boolean'],
            ],
        ];

        return config('call_recording.summarization.driver', 'assemblyai') === 'openai'
            ? $this->openAi($system, $user, $schema)
            : $this->assemblyAi($system, $user, $schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function assemblyAi(string $system, string $user, array $schema): array
    {
        $model = (string) config('call_recording.summarization.assemblyai_model', 'claude-sonnet-4-6');
        $apiKey = (string) config('services.assemblyai.api_key');

        if ($apiKey === '') {
            return $this->openAi($system, $user, $schema);
        }

        $response = Http::withHeaders(['authorization' => $apiKey])
            ->timeout(120)
            ->post('https://llm-gateway.assemblyai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'max_tokens' => 2500,
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'meeting_summary', 'strict' => true, 'schema' => $schema]],
                'post_processing_steps' => [['type' => 'json-repair']],
            ]);

        // The LLM gateway is a paid add-on; without it, OpenAI summarizes (as for calls).
        if (in_array($response->status(), [401, 403], true) && config('services.openai.api_key')) {
            Log::channel('call_ai')->warning('Meeting summary: AssemblyAI LLM Gateway unavailable, using OpenAI', ['status' => $response->status()]);

            return $this->openAi($system, $user, $schema);
        }

        return [$this->parse($response->successful(), $response->status(), data_get($response->json(), 'choices.0.message.content')), $model];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function openAi(string $system, string $user, array $schema): array
    {
        $model = (string) config('call_recording.summarization.model', 'gpt-4o');
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('No summarization API key (AssemblyAI or OpenAI)');

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'meeting_summary', 'strict' => true, 'schema' => $schema]],
            ]);

        return [$this->parse($response->successful(), $response->status(), data_get($response->json(), 'choices.0.message.content')), $model];
    }

    /** @return array<string, mixed> */
    protected function parse(bool $ok, int $status, mixed $content): array
    {
        $parsed = is_string($content) ? json_decode($content, true) : null;

        if (! $ok || ! is_array($parsed)) {
            throw new RuntimeException('meeting_summary_failed_http_'.$status);
        }

        return $parsed;
    }
}
