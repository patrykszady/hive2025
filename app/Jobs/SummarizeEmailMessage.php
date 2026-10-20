<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The AI read a call transcript gets (SummarizeCallTranscript), for an email
 * with a project stakeholder: a short summary, every concrete action item
 * ("Patryk: Send the tile samples"), topics, next steps, sentiment and
 * intent — plus, when the stakeholder has several open projects, which one
 * the email is about.
 */
class SummarizeEmailMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public int $emailMessageId)
    {
    }

    public function uniqueId(): string
    {
        return 'summarize-email-message-'.$this->emailMessageId;
    }

    public function handle(): void
    {
        $email = EmailMessage::query()->find($this->emailMessageId);

        if (! $email || $email->summarized_at) {
            return;
        }

        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            return;
        }

        $model = (string) config('emails.stakeholder_sync.summary_model', 'gpt-4.1');
        $candidates = Project::withoutGlobalScopes()
            ->whereIn('id', (array) ($email->candidate_project_ids ?? []))
            ->get(['id', 'project_name', 'address', 'city']);

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->retry(2, 3000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($email, $candidates)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'email_summary', 'strict' => true, 'schema' => $this->schema()],
                ],
            ]);

        if (! $response->successful()) {
            Log::channel('call_ai')->warning('SummarizeEmailMessage: request failed', ['email_message_id' => $email->id, 'status' => $response->status()]);
            $response->throw();
        }

        $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($parsed)) {
            Log::channel('call_ai')->warning('SummarizeEmailMessage: unreadable answer', ['email_message_id' => $email->id]);

            return;
        }

        $projectId = $email->project_id;
        $picked = isset($parsed['project_id']) ? (int) $parsed['project_id'] : null;
        if (! $projectId && $picked && $candidates->contains('id', $picked)) {
            $projectId = $picked;
        }

        $email->forceFill([
            'summary_model' => $model,
            'summary' => $this->text($parsed['summary'] ?? null, 2000),
            'action_items' => $this->list($parsed['action_items'] ?? []),
            'topics' => $this->list($parsed['topics'] ?? []),
            'next_steps' => $this->list($parsed['next_steps'] ?? []),
            'sentiment' => $this->text($parsed['sentiment'] ?? null, 16),
            'intent' => $this->text($parsed['intent'] ?? null, 255),
            'project_id' => $projectId,
            'summarized_at' => now(),
        ])->save();
    }

    /**
     * The new part of an email, without the quoted history under it — what
     * the timeline shows and what the summary is about.
     */
    public static function latestPart(string $body): string
    {
        $markers = [
            '/^\s*On .{3,200}wrote:\s*$/mi',
            '/^\s*-{2,}\s*Original Message\s*-{2,}\s*$/mi',
            '/^\s*From:\s.+\R\s*(Sent|Date):\s.+$/mi',
            '/^\s*_{10,}\s*$/m',
        ];

        $cut = strlen($body);
        foreach ($markers as $marker) {
            if (preg_match($marker, $body, $match, PREG_OFFSET_CAPTURE) && $match[0][1] > 0) {
                $cut = min($cut, $match[0][1]);
            }
        }

        $latest = trim(substr($body, 0, $cut));
        $latest = preg_replace('/^>.*$/m', '', $latest) ?? $latest;

        return trim(preg_replace("/\n{3,}/", "\n\n", $latest) ?? $latest);
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You summarize emails for GS Construction, a residential remodeling general contractor, the way its phone calls are summarized. The email is between the company's team and a project stakeholder (a homeowner client or a subcontractor/supplier).

Write in English, refer to people by first name, and stay factual — never invent anything that is not in the email.

- summary: 1-3 sentences on why the email was sent, what it says, and what was decided or asked. Skip greetings and sign-offs.
- action_items: every concrete task someone is asked to do or commits to, as a short verb-first instruction, prefixed with who is responsible when known ("Patryk: Send the revised tile quote", "Katie: Pick the vanity color"). Empty when there are none. Do not pad with vague or implied tasks.
- topics: short noun phrases.
- next_steps: the expected upcoming sequence of events.
- sentiment: positive, neutral, negative or mixed.
- intent: a few words on what the sender wants ("schedule a repair", "approve change order", "send invoice").
- project_id: when several projects are listed, the one the email is about (match addresses, rooms and trades). Null when unclear or only one is listed.

Quoted history below the new message is context only: summarize the NEW message. Dates: resolve "tomorrow", "Monday" etc. against the email's sent time; never invent a month.
PROMPT;
    }

    protected function userPrompt(EmailMessage $email, $candidates): string
    {
        $staff = $email->mailbox ? (User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email->mailbox])->value('first_name') ?: $email->mailbox) : 'the team';
        $latest = self::latestPart((string) $email->body_text);
        $history = Str::limit(trim(Str::after((string) $email->body_text, $latest)), 1500, '');

        $projects = $candidates->map(fn (Project $project) => "- id {$project->id}: ".trim(($project->project_name ?: 'Untitled').', '.trim(implode(', ', array_filter([$project->address, $project->city])))))->implode("\n");

        return 'Direction: '.($email->isInbound() ? 'received from the stakeholder' : 'sent by the team')."\n"
            .'Mailbox: '.($email->mailbox ?? 'unknown')." ({$staff})\n"
            .'Sent: '.$email->sent_at->copy()->setTimezone('America/Chicago')->format('l, M j, Y g:i A T')."\n"
            .'From: '.trim(($email->from_name ? $email->from_name.' ' : '')."<{$email->from_email}>")."\n"
            .'To: '.implode(', ', (array) $email->to)."\n"
            .((array) $email->cc !== [] ? 'Cc: '.implode(', ', (array) $email->cc)."\n" : '')
            .'Subject: '.($email->subject ?? '')."\n"
            .($projects !== '' ? "Projects:\n{$projects}\n" : '')
            .'Attachments: '.(collect((array) $email->attachments)->pluck('name')->implode(', ') ?: 'none')."\n\n"
            ."New message:\n".Str::limit($latest, 8000, '')
            .($history !== '' ? "\n\nQuoted history (context only):\n{$history}" : '');
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'action_items', 'topics', 'next_steps', 'sentiment', 'intent', 'project_id'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'action_items' => $list,
                'topics' => $list,
                'next_steps' => $list,
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative', 'mixed']],
                'intent' => ['type' => 'string'],
                'project_id' => ['type' => ['integer', 'null']],
            ],
        ];
    }

    protected function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }

    /** @return list<string> */
    protected function list(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => Str::limit(trim($value), 300, ''))
            ->values()
            ->all();
    }
}
