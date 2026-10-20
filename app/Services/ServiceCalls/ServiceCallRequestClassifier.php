<?php

namespace App\Services\ServiceCalls;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads one email from a known client and decides whether it asks GS to come
 * back and fix something on work already done — a service call — and if so,
 * which of the client's projects it is about and what the task should say.
 *
 * Returns null when the model could not be asked (no key, outage, junk
 * reply): the caller then decides nothing, and the next read tries again.
 */
class ServiceCallRequestClassifier
{
    private const SYSTEM = <<<'TXT'
You read email that an EXISTING client of GS Construction (a residential
remodeling general contractor in the Chicago suburbs) sent to the company.
The client's projects with GS are listed with the email.

Decide whether the email is a SERVICE REQUEST: the client reports a problem,
defect, failure or wear on work GS already did, or asks GS to come back to
fix, adjust, seal, touch up, replace or look at something from that work.
Warranty and punch-list items count ("grout is cracking", "the shower door
drips", "a cabinet door won't close", "paint is peeling by the window").

NOT a service request:
- new work or a new project ("we'd love to do the kitchen next", "can you
  quote a deck") — that is a sales enquiry, even from a happy client;
- payments, invoices, receipts, lien waivers, insurance or paperwork;
- thanks, reviews, photos without a problem, holiday wishes;
- replies about a visit that is already scheduled, or answers to our own
  questions that raise no new problem;
- newsletters, notifications, forwards of other people's mail.
An email that asks for a fix AND mentions new work is a service request.

When it is a service request:
- project_id: the listed project the problem belongs to (match rooms, trades
  and addresses to project names). Null when none of them fits.
- task_title: an imperative task for the crew, at most 60 characters,
  specific to what they wrote ("Seal cracked grout in both bathrooms").
- issue: a short noun phrase that completes "Thanks for letting us know
  about …", lowercase unless a proper noun ("the cracked grout in both
  bathrooms").
Otherwise use null for all three.

`confidence` is how sure you are of `is_service_request`, from 0 to 1 — not
how likely it is to be one. A thank-you note you are certain about is
is_service_request=false with confidence 0.95.
TXT;

    /**
     * @param  Collection<int, Project>  $projects  the client's eligible projects, latestStatus loaded
     * @return array{is_service_request: bool, confidence: float, project_id: ?int, task_title: ?string, issue: ?string, reason: ?string, model: string}|null
     */
    public function classify(string $fromEmail, ?string $fromName, string $subject, string $body, Collection $projects): ?array
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            return null;
        }

        $model = (string) config('service_calls.email_intake.model', 'gpt-4.1');

        $projectLines = $projects->map(fn (Project $project): string => sprintf(
            '- id %d: %s, %s (%s since %s)',
            $project->id,
            $project->project_name ?: 'Untitled project',
            trim(implode(', ', array_filter([$project->address, $project->city]))) ?: 'no address',
            $project->latestStatus?->title ?? 'unknown status',
            $project->latestStatus?->start_date ? Carbon::parse($project->latestStatus->start_date)->format('M j, Y') : 'unknown',
        ))->implode("\n");

        $from = trim(($fromName ? "{$fromName} " : '')."<{$fromEmail}>");
        $user = "Client's projects:\n{$projectLines}\n\nFrom: {$from}\nSubject: {$subject}\n\n".Str::limit($body, 6000, '');

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_service_request', 'confidence', 'project_id', 'task_title', 'issue', 'reason'],
            'properties' => [
                'is_service_request' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'project_id' => ['type' => ['integer', 'null']],
                'task_title' => ['type' => ['string', 'null']],
                'issue' => ['type' => ['string', 'null']],
                'reason' => ['type' => 'string'],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->retry(2, 2000, throw: false)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'service_call_request', 'strict' => true, 'schema' => $schema],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Service call intake: classification threw', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('nylas')->warning('Service call intake: classification failed', ['status' => $response->status()]);

            return null;
        }

        $data = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($data) || ! array_key_exists('is_service_request', $data)) {
            return null;
        }

        return [
            'is_service_request' => (bool) $data['is_service_request'],
            'confidence' => (float) ($data['confidence'] ?? 0),
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'task_title' => $this->clean($data['task_title'] ?? null, 60),
            'issue' => $this->clean($data['issue'] ?? null, 120),
            'reason' => $this->clean($data['reason'] ?? null, 1000),
            'model' => $model,
        ];
    }

    /** The model sometimes fills a nullable field with the string "null". */
    private function clean(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (in_array(mb_strtolower($value), ['', 'null', 'none', 'n/a', 'unknown'], true)) {
            return null;
        }

        return Str::limit($value, $limit, '');
    }
}
