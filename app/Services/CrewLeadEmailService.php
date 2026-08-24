<?php

namespace App\Services;

use App\Models\CrewEmailIngest;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Services\LeadAddressCompleter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns email sent to crew@gs.construction into CRM leads.
 *
 * The mailbox is a genuine human inbox, not a robot drop-box: it carries
 * prospect enquiries, outbound client mail GS itself sent, vendor traffic,
 * newsletters and at least one legal demand letter. So the work is mostly
 * deciding what NOT to act on. That decision happens here rather than in a
 * mail rule, because "is this a prospect?" is a judgement no Outlook filter
 * can make.
 *
 * Order matters: cheap deterministic triage runs first and settles most
 * messages for free; only what survives costs an LLM call.
 *
 * The lead is assembled from the sender and body BEFORE extraction runs, and
 * extraction only merges over that. A model outage, a malformed response or a
 * missing API key therefore degrades the lead's tidiness, never its existence.
 * A junk lead costs one click; a dropped enquiry costs a job.
 */
class CrewLeadEmailService
{
    public function __construct(
        protected NylasService $nylas,
    ) {}

    /** @return array{fetched:int, leads:int, skipped:int, failed:int, details:array<int,array<string,mixed>>} */
    public function ingest(bool $dryRun = false, ?int $limit = null, ?\DateTimeInterface $since = null): array
    {
        $cfg = config('nylas.crew_leads');
        $mailbox = (string) $cfg['mailbox'];
        $since ??= $this->since();

        $out = ['fetched' => 0, 'leads' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        $messages = $this->fetch($mailbox, $limit ?? (int) $cfg['poll_limit'], $since, $grantId);
        if ($messages === null) {
            Log::channel('nylas')->error('Crew leads: no usable grant could read the shared mailbox', [
                'mailbox' => $mailbox,
            ]);

            return $out;
        }

        $out['fetched'] = count($messages);

        foreach ($messages as $message) {
            $result = $this->ingestMessage($message, $mailbox, $grantId, $dryRun);
            $out['details'][] = $result;
            $key = match ($result['status']) {
                CrewEmailIngest::STATUS_LEAD => 'leads',
                CrewEmailIngest::STATUS_FAILED => 'failed',
                default => 'skipped',
            };
            $out[$key]++;
        }

        if (! $dryRun && $out['fetched'] > 0) {
            $this->rememberWatermark($messages);
        }

        return $out;
    }

    /**
     * Read the shared mailbox through whichever grant still works.
     *
     * `shared_from` is what makes this possible at all: crew@ has no grant of
     * its own, so Nylas proxies the read to Microsoft Graph using a user grant
     * that has access. Grants are tried in order so an expired one degrades to
     * the next instead of stopping lead capture.
     *
     * @return array<int, array<string, mixed>>|null  null = every grant failed
     */
    protected function fetch(string $mailbox, int $limit, \DateTimeInterface $since, ?string &$grantId = null): ?array
    {
        foreach ((array) config('nylas.crew_leads.grant_ids') as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            // Deliberately the raw endpoint rather than NylasService::getMessages():
            // that helper runs `in` through resolveFolderIdentifier(), which
            // knows nothing about shared mailboxes and would rewrite or drop
            // the folder id.
            $response = Http::withToken(config('nylas.api_key'))
                ->timeout(60)
                ->retry(2, 2000, throw: false)
                ->get(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/') . "/v3/grants/{$candidate}/messages", [
                    'shared_from' => $mailbox,
                    'in' => $this->inboxFolderId($candidate, $mailbox),
                    'limit' => $limit,
                    'received_after' => $since->getTimestamp(),
                    // Headers carry the bulk-mail markers (List-Unsubscribe,
                    // Precedence, Auto-Submitted) that let triage reject
                    // marketing for free, and the RFC Message-ID used as the
                    // stable dedupe identity. Without this the header checks
                    // silently never fire — promotional mail reached the
                    // classifier and dedupe fell back to the Nylas id.
                    'fields' => 'include_headers',
                ]);

            if ($response->successful()) {
                $grantId = $candidate;

                return (array) ($response->json('data') ?? []);
            }

            Log::channel('nylas')->warning('Crew leads: grant could not read shared mailbox', [
                'grant_id' => $candidate,
                'status' => $response->status(),
                'body' => Str::limit((string) $response->body(), 300),
            ]);
        }

        return null;
    }

    /** Resolve (and cache) the shared mailbox's Inbox folder id for a grant. */
    protected function inboxFolderId(string $grantId, string $mailbox): ?string
    {
        return cache()->remember(
            "crew_leads:inbox:{$grantId}:{$mailbox}",
            now()->addDay(),
            function () use ($grantId, $mailbox): ?string {
                $response = Http::withToken(config('nylas.api_key'))
                    ->timeout(45)
                    ->retry(2, 2000, throw: false)
                    ->get(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/') . "/v3/grants/{$grantId}/folders", [
                        'shared_from' => $mailbox,
                    ]);

                if (! $response->successful()) {
                    return null;
                }

                // The response mixes the shared mailbox's folders with the
                // grant owner's own. The shared mailbox is the smaller one, so
                // pick the FIRST Inbox — shared folders are returned first.
                foreach ((array) $response->json('data') as $folder) {
                    if (strcasecmp((string) ($folder['name'] ?? ''), 'Inbox') === 0) {
                        return $folder['id'] ?? null;
                    }
                }

                return null;
            },
        );
    }

    /**
     * Read each grant's OWN inbox for replies from known lead senders.
     *
     * Kathy Moseler's phone number sat in a reply to patryk@gs.construction
     * for three days while crew@ ingestion looked the other way — leads
     * answer whichever address last emailed them. This sweep runs those
     * personal-inbox replies through the same pipeline (file on the lead,
     * mine missing contact fields, hand the ball back). It NEVER creates
     * leads: personal mail is not an enquiry channel.
     *
     * @return array{mailboxes: int, fetched: int, replies: int}
     */
    public function sweepPersonalInboxes(?int $limit = null, ?\DateTimeInterface $since = null): array
    {
        $out = ['mailboxes' => 0, 'fetched' => 0, 'replies' => 0];
        $since ??= now()->subDays(2);
        $base = rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/');

        foreach ((array) config('nylas.crew_leads.grant_ids') as $grantId) {
            $grantId = trim((string) $grantId);

            if ($grantId === '') {
                continue;
            }

            $response = Http::withToken(config('nylas.api_key'))
                ->timeout(60)
                ->retry(2, 2000, throw: false)
                ->get("$base/v3/grants/{$grantId}/messages", array_filter([
                    'in' => $this->ownInboxFolderId($grantId),
                    'limit' => $limit ?? 50,
                    'received_after' => $since->getTimestamp(),
                    // RFC threading headers feed EmailReplyDetector's exact
                    // In-Reply-To/References match.
                    'fields' => 'include_headers',
                ]));

            if (! $response->successful()) {
                Log::channel('nylas')->warning('Personal inbox sweep: grant unreadable', [
                    'grant_id' => $grantId,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $out['mailboxes']++;
            $mailbox = $this->grantMailboxEmail($grantId);

            foreach ((array) $response->json('data') as $message) {
                $out['fetched']++;

                if ($this->processPersonalInboxMessage($message, $grantId, $mailbox) === 'filed') {
                    $out['replies']++;
                }
            }
        }

        return $out;
    }

    /**
     * One inbound personal-mailbox message through the reply pipeline.
     *
     * Shared verbatim between the five-minute sweep and the Nylas
     * `message.created` webhook, so a reply is handled identically whether it
     * arrived by push or by poll — and safely twice, since every write below
     * dedupes: the detector on nylas_message_id, the ledger on its own key.
     *
     * Returns the outcome: 'filed' (reply landed on a lead), or the skip
     * reason ('invalid', 'internal', 'already_ingested', 'not_a_lead_reply').
     */
    public function processPersonalInboxMessage(array $message, string $grantId, string $mailbox): string
    {
        $nylasId = (string) ($message['id'] ?? '');
        $from = ($message['from'][0] ?? []);
        $fromEmail = strtolower(trim((string) ($from['email'] ?? '')));

        if ($nylasId === '' || $fromEmail === '') {
            return 'invalid';
        }

        // Team and system mail is not a lead reply.
        if (str_ends_with($fromEmail, '@gs.construction') || str_ends_with($fromEmail, '@hive.contractors')) {
            return 'internal';
        }

        // Badge the answered thread BEFORE the lead-only and ledger skips:
        // estimate replies from established clients have no lead row, and a
        // reply the ledger already ingested may still predate this badge
        // existing. The detector gates itself on reply signals and dedupes on
        // the message id, so calling it for every external message is safe
        // and idempotent.
        $headers = $this->headerMap($message);
        app(\App\Services\EmailReplyDetector::class)->record([
            'nylas_message_id' => $nylasId,
            'from_email' => $fromEmail,
            'subject' => (string) ($message['subject'] ?? ''),
            'thread_id' => $message['thread_id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references' => $headers['references'] ?? null,
            'message_at' => isset($message['date']) ? now()->setTimestamp((int) $message['date']) : null,
            'mailbox' => $mailbox,
        ]);

        // Same dedupe ledger the crew@ ingest uses — a reply CC'd to crew@ is
        // captured once, whichever sweep or webhook sees it first.
        if (CrewEmailIngest::where('nylas_message_id', $nylasId)->exists()) {
            return 'already_ingested';
        }

        // Only senders we already know as leads.
        if (! Lead::withoutGlobalScopes()->whereNull('deleted_at')->where('lead_data->email', $fromEmail)->exists()) {
            return 'not_a_lead_reply';
        }

        $body = $this->plainBody($message);
        $row = [
            'nylas_message_id' => $nylasId,
            'grant_id' => $grantId,
            'mailbox' => $mailbox,
            'thread_id' => $message['thread_id'] ?? null,
            'from_email' => $fromEmail,
            'from_name' => $from['name'] ?? null,
            'recipients' => [
                'to' => array_column($message['to'] ?? [], 'email'),
                'cc' => array_column($message['cc'] ?? [], 'email'),
            ],
            'subject' => Str::limit((string) ($message['subject'] ?? ''), 500, ''),
            'message_at' => isset($message['date']) ? now()->setTimestamp((int) $message['date']) : null,
            'body_snippet' => Str::limit($body, 2000, ''),
        ];

        $leadId = $this->recordLeadReply($row, $body);

        CrewEmailIngest::updateOrCreate(['nylas_message_id' => $nylasId], $row + [
            'status' => CrewEmailIngest::STATUS_SKIPPED,
            'skip_reason' => 'reply',
            'is_lead' => false,
            'lead_id' => $leadId,
        ]);

        if (! $leadId) {
            return 'not_a_lead_reply';
        }

        Log::channel('nylas')->info('Personal inbox reply filed on lead', [
            'lead_id' => $leadId,
            'mailbox' => $mailbox,
        ]);

        return 'filed';
    }

    /** The grant's mailbox address, cached a day (it never changes in practice). */
    public function grantMailboxEmail(string $grantId): string
    {
        return (string) cache()->remember(
            "nylas:grant-email:{$grantId}",
            now()->addDay(),
            function () use ($grantId) {
                $base = rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/');

                return strtolower((string) (Http::withToken(config('nylas.api_key'))
                    ->timeout(15)->retry(2, 2000, throw: false)
                    ->get("$base/v3/grants/{$grantId}")->json('data.email') ?? $grantId));
            }
        );
    }

    /** The grant's own Inbox folder id (no shared_from), cached a day. */
    protected function ownInboxFolderId(string $grantId): ?string
    {
        return cache()->remember(
            "crew_leads:own_inbox:{$grantId}",
            now()->addDay(),
            function () use ($grantId): ?string {
                $response = Http::withToken(config('nylas.api_key'))
                    ->timeout(45)
                    ->retry(2, 2000, throw: false)
                    ->get(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/')."/v3/grants/{$grantId}/folders");

                if (! $response->successful()) {
                    return null;
                }

                foreach ((array) $response->json('data') as $folder) {
                    $attributes = array_map('strtolower', (array) ($folder['attributes'] ?? []));

                    if (strcasecmp((string) ($folder['name'] ?? ''), 'Inbox') === 0 || in_array('\\inbox', $attributes, true)) {
                        return $folder['id'] ?? null;
                    }
                }

                return null;
            },
        );
    }

    /** @return array<string, mixed> */
    protected function ingestMessage(array $message, string $mailbox, ?string $grantId, bool $dryRun): array
    {
        $nylasId = (string) ($message['id'] ?? '');
        $from = ($message['from'][0] ?? []);
        $fromEmail = strtolower(trim((string) ($from['email'] ?? '')));
        $subject = (string) ($message['subject'] ?? '');
        $body = $this->plainBody($message);

        $base = [
            'nylas_message_id' => $nylasId,
            'grant_id' => (string) $grantId,
            'mailbox' => $mailbox,
            'thread_id' => $message['thread_id'] ?? null,
            'from_email' => $fromEmail ?: null,
            'from_name' => $from['name'] ?? null,
            'recipients' => [
                'to' => array_column($message['to'] ?? [], 'email'),
                'cc' => array_column($message['cc'] ?? [], 'email'),
            ],
            'subject' => Str::limit($subject, 500, ''),
            'message_at' => isset($message['date']) ? now()->setTimestamp((int) $message['date']) : null,
            'body_snippet' => Str::limit($body, 2000, ''),
        ];

        $summary = [
            'subject' => Str::limit($subject, 60, ''),
            'from' => $fromEmail,
        ];

        // Already handled on an earlier run.
        if (! $dryRun && $nylasId !== '' && CrewEmailIngest::where('nylas_message_id', $nylasId)->exists()) {
            return $summary + ['status' => CrewEmailIngest::STATUS_SKIPPED, 'reason' => 'already_ingested'];
        }

        if ($reason = $this->triage($fromEmail, $subject, $body, $message)) {
            // A reply is skipped as a NEW lead, but it is not nothing: it's a
            // lead answering us, and until now it lived only in Outlook while
            // the CRM showed "Replied" as if the ball were still in their
            // court. Attach it to the lead it answers and put that lead back
            // in front of the team.
            $repliedLeadId = null;
            if ($reason === 'reply' && ! $dryRun) {
                $repliedLeadId = $this->recordLeadReply($base, $body);

                // Independently of whether the sender is a LEAD, badge the
                // email thread this answers. Estimate replies from established
                // clients have no lead row, but they absolutely have a thread.
                $headers = $this->headerMap($message);
                app(\App\Services\EmailReplyDetector::class)->record([
                    'nylas_message_id' => $nylasId,
                    'from_email' => $fromEmail,
                    'subject' => $subject,
                    'thread_id' => $base['thread_id'] ?? null,
                    'in_reply_to' => $headers['in-reply-to'] ?? null,
                    'references' => $headers['references'] ?? null,
                    'message_at' => $base['message_at'] ?? null,
                    'mailbox' => $mailbox,
                ]);
            }

            if (! $dryRun) {
                CrewEmailIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                    'status' => CrewEmailIngest::STATUS_SKIPPED,
                    'skip_reason' => $reason,
                    'is_lead' => false,
                    'lead_id' => $repliedLeadId,
                ]);
            }

            return $summary + ['status' => CrewEmailIngest::STATUS_SKIPPED, 'reason' => $reason, 'lead_id' => $repliedLeadId];
        }

        $verdict = $this->classify($subject, $body, $fromEmail);

        // Only a CONFIDENT "not a lead" discards. An unsure model creates the
        // lead — the asymmetry is intentional.
        if ($verdict['is_lead'] === false && $verdict['confidence'] >= 0.8) {
            if (! $dryRun) {
                CrewEmailIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                    'status' => CrewEmailIngest::STATUS_SKIPPED,
                    'skip_reason' => 'not_a_lead',
                    'is_lead' => false,
                    'confidence' => $verdict['confidence'],
                ]);
            }

            return $summary + ['status' => CrewEmailIngest::STATUS_SKIPPED, 'reason' => 'not_a_lead', 'confidence' => $verdict['confidence']];
        }

        if ($dryRun) {
            return $summary + ['status' => CrewEmailIngest::STATUS_LEAD, 'confidence' => $verdict['confidence'], 'reason' => $verdict['reason'] ?? null];
        }

        try {
            $lead = $this->createLead($message, $base, $body, $verdict);

            // An enquiry without an address or phone can't be scheduled —
            // ask the sender for exactly what's missing, right away, once.
            $this->requestMissingInfo($lead, $base);

            CrewEmailIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                'status' => CrewEmailIngest::STATUS_LEAD,
                'is_lead' => true,
                'confidence' => $verdict['confidence'],
                'extraction_status' => $verdict['extraction_status'],
                'lead_id' => $lead->id,
            ]);

            return $summary + ['status' => CrewEmailIngest::STATUS_LEAD, 'lead_id' => $lead->id];
        } catch (\Throwable $e) {
            CrewEmailIngest::updateOrCreate(['nylas_message_id' => $nylasId], $base + [
                'status' => CrewEmailIngest::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            Log::channel('nylas')->error('Crew leads: failed to create lead', [
                'nylas_message_id' => $nylasId,
                'error' => $e->getMessage(),
            ]);

            return $summary + ['status' => CrewEmailIngest::STATUS_FAILED, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Cheap, certain exclusions. Returns a skip reason or null to continue.
     *
     * Everything here is something no model should be asked to judge and no
     * model should be paid to judge.
     */
    protected function triage(string $fromEmail, string $subject, string $body, array $message): ?string
    {
        if ($fromEmail === '') {
            return 'no_sender';
        }

        // Outbound mail GS sent to its own clients lands in this Inbox. Without
        // this, every estimate and follow-up becomes a fake lead — verified as
        // 3 of the 5 most recent messages.
        $domain = Str::after($fromEmail, '@');
        foreach ((array) config('nylas.crew_leads.internal_domains') as $internal) {
            if ($domain === $internal || str_ends_with($domain, '.' . $internal)) {
                return 'internal';
            }
        }

        if (preg_match('/^(noreply|no-reply|donotreply|do-not-reply|mailer-daemon|postmaster|bounce)/i', $fromEmail)) {
            return 'automated';
        }

        $headers = $this->headerMap($message);
        if (isset($headers['list-unsubscribe'])
            || (isset($headers['auto-submitted']) && strtolower($headers['auto-submitted']) !== 'no')
            || (isset($headers['precedence']) && preg_match('/bulk|list|auto_reply/i', $headers['precedence']))
            || isset($headers['x-auto-response-suppress'])) {
            return 'automated';
        }

        // A reply is a continuation of a conversation GS is already having —
        // typically someone answering a consultation or estimate we sent. The
        // lead (or client) already exists; minting a new one duplicates it and
        // makes the pipeline lie about how much fresh demand came in.
        //
        // A FORWARD is not a reply. A homeowner who prepares one bid-request
        // email and forwards it to every contractor she found is a brand-new
        // enquiry — exactly the mail this pipeline exists to catch — and her
        // subject starts with "Fwd:" while her headers carry References to
        // the original in HER OWN mailbox. So a forward is never skipped
        // here: it goes on to the classifier, which still rejects forwarded
        // newsletters and solicitations on their content.
        //
        // In-Reply-To / References are the RFC-correct reply signal and
        // survive subject editing — but only when the subject doesn't say
        // forward, since forwarding clients set those headers too. The "Re:"
        // prefix family is the fallback for senders whose client omits the
        // headers, covering the localised forms Outlook emits.
        $isForward = (bool) preg_match('/^\s*(fw|fwd|tr|wg|pd|i)\s*(\[\d+\])?\s*:/i', $subject);

        if (! $isForward && (isset($headers['in-reply-to']) || isset($headers['references']))) {
            return 'reply';
        }

        if (! $isForward && preg_match('/^\s*(re|aw|sv|vs|odp)\s*(\[\d+\])?\s*:/i', $subject)) {
            return 'reply';
        }

        if (trim($subject) === '' && trim($body) === '') {
            return 'empty';
        }

        return null;
    }

    /** @return array<string, string> lowercased header name => value */
    protected function headerMap(array $message): array
    {
        $out = [];
        foreach ((array) ($message['headers'] ?? []) as $key => $header) {
            if (is_array($header) && isset($header['name'])) {
                $out[strtolower((string) $header['name'])] = (string) ($header['value'] ?? '');
            } elseif (is_string($key)) {
                $out[strtolower($key)] = (string) $header;
            }
        }

        return $out;
    }

    /**
     * Ask the model whether this is a prospect enquiry, and pull out the
     * details worth having on the lead.
     *
     * One call does both: a second round trip to extract after classifying
     * doubles latency and cost for the same text.
     *
     * Public so the same judgement can be applied to leads that arrived
     * through the website form, which has no triage of its own.
     *
     * @return array{is_lead:?bool, confidence:float, reason:?string, extraction_status:string, fields:array<string,mixed>}
     */
    public function classify(string $subject, string $body, string $fromEmail): array
    {
        $fallback = [
            'is_lead' => null,
            'confidence' => 0.0,
            'reason' => null,
            'extraction_status' => 'skipped',
            'fields' => [],
        ];

        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return $fallback;
        }

        $system = <<<'TXT'
You triage the shared inbox of GS Construction, a residential remodeling
general contractor in the Chicago suburbs.

Decide whether a message is a PROSPECT ENQUIRY: someone outside the company
asking about work they want done, or responding to an estimate they requested.

The direction of the offer is what decides it. A lead is someone who wants to
BUY construction work from GS. Anyone SELLING something to GS is not a lead,
however friendly the wording — subcontractors and suppliers touting their
services, partnership or "collaboration" proposals, marketing and SEO
agencies, recruiters, software vendors. This holds in any language: a Polish
"oferta współpracy" or "współpraca" is a cooperation offer, i.e. a
solicitation, not an enquiry.

Also NOT enquiries: mail the company itself sent, invoices and payment
notices, newsletters and promotions, legal or demand letters, automated
notifications, and platform emails that merely announce a lead exists
elsewhere.

When a message IS an enquiry, extract what it actually states. Never invent a
value — use null for anything not present. Quote the address exactly as
written. Keep scope_summary to one or two sentences in plain language.

Enquiries often come from a couple: put EVERY name in `name` as written
("Amy Dusto and Chris Ecker") and EVERY number in `phone` separated by " / ",
in the same order as the names. Give `zip` only when the message states it —
a guessed ZIP is worse than none.
TXT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_lead', 'confidence', 'reason', 'name', 'phone', 'address', 'city', 'zip', 'project_type', 'scope_summary', 'timeline', 'budget'],
            'properties' => [
                'is_lead' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
                'name' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
                'address' => ['type' => ['string', 'null']],
                'city' => ['type' => ['string', 'null']],
                'zip' => ['type' => ['string', 'null']],
                'project_type' => ['type' => ['string', 'null']],
                'scope_summary' => ['type' => ['string', 'null']],
                'timeline' => ['type' => ['string', 'null']],
                'budget' => ['type' => ['string', 'null']],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "From: {$fromEmail}\nSubject: {$subject}\n\n" . Str::limit($body, 6000, '')],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'crew_lead_triage', 'strict' => true, 'schema' => $schema],
                    ],
                ]);

            if (! $response->successful()) {
                Log::channel('nylas')->warning('Crew leads: classification request failed', [
                    'status' => $response->status(),
                ]);

                return $fallback + ['extraction_status' => 'failed'];
            }

            $data = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);
            if (! is_array($data)) {
                return array_merge($fallback, ['extraction_status' => 'failed']);
            }

            // The schema says string-or-null, and the model occasionally
            // satisfies it with the STRING "null" — which then renders as
            // the word "null" in every form field it lands in. Junk
            // placeholders become real nulls before anything merges.
            $data = array_map(function ($value) {
                if (is_string($value)
                    && in_array(mb_strtolower(trim($value)), ['null', 'none', 'n/a', 'unknown', ''], true)) {
                    return null;
                }

                return $value;
            }, $data);

            return [
                'is_lead' => (bool) ($data['is_lead'] ?? false),
                'confidence' => (float) ($data['confidence'] ?? 0),
                'reason' => $data['reason'] ?? null,
                'extraction_status' => 'ok',
                'fields' => $data,
            ];
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Crew leads: classification threw', ['error' => $e->getMessage()]);

            return array_merge($fallback, ['extraction_status' => 'failed']);
        }
    }

    /**
     * Build the Lead from the email itself, then let extraction improve it.
     *
     * This ordering is the whole safety story: everything the CRM needs to act
     * on the enquiry — who sent it, what they wrote — comes from the message,
     * not the model.
     */
    protected function createLead(array $message, array $base, string $body, array $verdict): Lead
    {
        $cfg = config('nylas.crew_leads');
        $fields = $verdict['fields'];

        $leadData = array_filter([
            'name' => $fields['name'] ?? $base['from_name'] ?? null,
            'email' => $base['from_email'],
            // Couples write in together and CC each other. Those addresses are
            // the other people on the enquiry — keep them, or provisioning has
            // nothing to reach the second person by.
            'cc_emails' => $this->partnerEmails($base),
            'phone' => $fields['phone'] ?? null,
            'address' => $fields['address'] ?? null,
            'city' => $fields['city'] ?? null,
            'zip' => $fields['zip'] ?? null,
            'project_type' => $fields['project_type'] ?? null,
            'scope_summary' => $fields['scope_summary'] ?? null,
            'timeline' => $fields['timeline'] ?? null,
            'budget' => $fields['budget'] ?? null,
            'subject' => $base['subject'],
            // The full text, always. Whatever extraction missed is still here.
            'message' => $body,
            'source_mailbox' => $base['mailbox'],
            'extraction_status' => $verdict['extraction_status'],
        ], fn ($v) => $v !== null && $v !== '');

        $leadData = app(LeadAddressCompleter::class)->complete($leadData);

        $date = $base['message_at'] ?? now();
        $vendorId = (int) $cfg['vendor_id'];

        $lead = Lead::create([
            'date' => $date,
            'origin' => 'Email',
            'external_source' => (string) $cfg['external_source'],
            'external_id' => $this->externalId($message, $base),
            'lead_data' => $leadData,
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => (int) $cfg['created_by_user_id'],
            // `leads.notes` is varchar(255) and shared with the rest of the
            // CRM, so it gets a headline, not the email. The full body is
            // already on lead_data['message'], which is JSON and unbounded —
            // widening a shared column to fit an email body would be the
            // wrong trade.
            'notes' => Str::limit(
                trim(($base['subject'] ?? '') . ' — ' . ($fields['scope_summary'] ?? Str::squish($body))),
                250,
            ),
        ]);

        // Whatever the enquirer attached — a bid request form, drawings,
        // photos of the damage — is often the substance of the enquiry.
        // Failure is non-fatal: the lead exists either way, tidier with the
        // files, complete without them.
        try {
            $files = $this->storeAttachments($message, $base, $lead);

            if ($files !== []) {
                $lead->update(['lead_data' => $leadData + ['attachments' => $files]]);
            }
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Crew leads: attachment capture failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Parity with the website-form path (Api\LeadsController::store).
        // Without the status row the lead has no pipeline stage and does not
        // appear where the team works leads; without provisioning it has no
        // contact record. An email lead must look exactly like a form lead
        // once it lands.
        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $vendorId,
            'created_at' => $date,
        ]);

        try {
            app(\App\Services\LeadContactProvisioner::class)->provision($lead->fresh());
        } catch (\Throwable $e) {
            // Contact provisioning is an enhancement, not the lead itself.
            Log::channel('nylas')->warning('Crew leads: contact provisioning failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $lead;
    }

    /**
     * File an email reply onto the lead who sent it. Returns the lead id, or
     * null when no lead matches the sender.
     *
     * The reply's text is kept on the lead (last 10, newest first) so the
     * conversation is readable where the team works leads, and a lead we had
     * already answered comes back to "New" — same semantics as the pick-times
     * page: the ball is in OUR court again.
     */
    protected function recordLeadReply(array $base, string $body): ?int
    {
        $fromEmail = (string) ($base['from_email'] ?? '');

        if ($fromEmail === '') {
            return null;
        }

        $lead = Lead::withoutGlobalScopes()
            ->where('lead_data->email', $fromEmail)
            ->latest('id')
            ->first();

        if (! $lead) {
            return null;
        }

        $data = $lead->lead_data instanceof \ArrayObject ? $lead->lead_data->toArray() : (array) $lead->lead_data;
        $replies = array_slice((array) ($data['email_replies'] ?? []), 0, 9);

        array_unshift($replies, array_filter([
            'at' => ($base['message_at'] ?? now())->toDateTimeString(),
            'subject' => $base['subject'] ?? null,
            'body' => Str::limit(trim($body), 1500, '…'),
        ]));

        $data['email_replies'] = array_values($replies);
        $lead->lead_data = $data;
        $lead->saveQuietly();

        // People often answer the "what's your address?" ask right here in
        // the reply — mine it for whatever contact fields are still missing.
        $this->fillMissingContactFromReply($lead->fresh(), $body);

        if ($lead->last_status?->title === 'Replied') {
            $lead->setStatus('New');
        }

        return $lead->id;
    }

    /**
     * Fill contact fields the lead still lacks from a reply's text — address,
     * city, state, zip, phone. Merge is missing-fields-only: anything already
     * on the lead wins over the model's reading, and junk placeholders never
     * land. Then provisioning gets a chance to build the client the lead was
     * waiting on.
     */
    protected function fillMissingContactFromReply(Lead $lead, string $body): void
    {
        try {
            $data = $lead->lead_data instanceof \ArrayObject ? $lead->lead_data->toArray() : (array) $lead->lead_data;

            $wanted = collect(['address', 'city', 'state', 'zip', 'phone'])
                ->filter(fn (string $key) => trim((string) ($data[$key] ?? '')) === '')
                ->values();

            if ($wanted->isEmpty()) {
                return;
            }

            $fields = $this->extractContactFromText($body);

            $dirty = false;
            foreach ($wanted as $key) {
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value !== '') {
                    $data[$key] = $value;
                    $dirty = true;
                }
            }

            if (! $dirty) {
                return;
            }

            $lead->lead_data = $data;
            $lead->saveQuietly();

            Log::channel('nylas')->info('Crew leads: contact fields filled from reply', [
                'lead_id' => $lead->id,
                'fields' => $wanted->all(),
            ]);

            app(\App\Services\LeadContactProvisioner::class)->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Crew leads: reply contact mining failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Contact fields stated in a piece of text, via the same model the
     * classifier uses. Empty array when no key is configured or the model
     * has nothing — never invented values.
     *
     * @return array<string, ?string>
     */
    protected function extractContactFromText(string $text): array
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '' || trim($text) === '') {
            return [];
        }

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['address', 'city', 'state', 'zip', 'phone'],
            'properties' => [
                'address' => ['type' => ['string', 'null']],
                'city' => ['type' => ['string', 'null']],
                'state' => ['type' => ['string', 'null']],
                'zip' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Extract the postal address parts and phone number the WRITER states as their own, from their message only (ignore quoted earlier messages where possible). Use null for anything not stated — never invent or guess. street address goes in `address` without city/state/zip.'],
                    ['role' => 'user', 'content' => Str::limit($text, 4000, '')],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'reply_contact', 'strict' => true, 'schema' => $schema],
                ],
            ]);

        if (! $response->successful()) {
            return [];
        }

        $fields = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($fields)) {
            return [];
        }

        return array_map(function ($value) {
            if (is_string($value)
                && in_array(mb_strtolower(trim($value)), ['null', 'none', 'n/a', 'unknown', ''], true)) {
                return null;
            }

            return $value;
        }, $fields);
    }

    /**
     * One automatic email back to a fresh enquiry that can't be scheduled
     * yet: name exactly what's missing (address, city, phone) and ask for it.
     * One-shot — the marker is written before the queue runs, so a crashed
     * worker can never re-ask.
     */
    protected function requestMissingInfo(Lead $lead, array $base): void
    {
        try {
            $data = $lead->lead_data instanceof \ArrayObject ? $lead->lead_data->toArray() : (array) $lead->lead_data;

            $email = trim((string) ($data['email'] ?? ''));

            if ($email === '' || isset($data['missing_info_requested_at'])) {
                return;
            }

            $missing = [];
            if (trim((string) ($data['address'] ?? '')) === '') {
                $missing[] = 'the project address';
            } elseif (trim((string) ($data['city'] ?? '')) === '') {
                $missing[] = 'your city or town';
            }
            if (trim((string) ($data['phone'] ?? '')) === '') {
                $missing[] = 'the best phone number to reach you';
            }

            if ($missing === []) {
                return;
            }

            $companyEmail = CompanyEmail::withoutGlobalScopes()
                ->where('vendor_id', $lead->belongs_to_vendor_id)
                ->whereNotNull('grant_id')
                ->orderBy('id')
                ->first();

            if (! $companyEmail) {
                return;
            }

            $vendor = \App\Models\Vendor::withoutGlobalScopes()->find($lead->belongs_to_vendor_id);
            $vendorName = $vendor?->name ?? config('app.name');
            $shortVendorName = data_get($vendor?->options, 'short_name') ?: $vendorName;
            $firstName = strtok(trim((string) ($data['name'] ?? '')), ' ');

            $list = count($missing) === 1
                ? $missing[0]
                : implode(' and ', [implode(', ', array_slice($missing, 0, -1)), end($missing)]);

            $htmlBody = '<p>Hi'.($firstName ? ' '.e($firstName) : '').',</p>'
                .'<p>Thank you for reaching out to '.e($vendorName).'! '
                .'To get your consultation scheduled, could you send over '.e($list).'?</p>'
                .\App\Support\EmailSignature::html($shortVendorName);

            // Written BEFORE dispatch: losing the job costs one ask; crashing
            // after the send must never earn the sender a second one.
            $data['missing_info_requested_at'] = now()->toDateTimeString();
            $lead->lead_data = $data;
            $lead->saveQuietly();

            \App\Jobs\SendLeadReplyJob::dispatch(
                leadId: $lead->id,
                companyEmailId: $companyEmail->id,
                userId: (int) $lead->created_by_user_id,
                recipients: [$email],
                fromEmail: $companyEmail->email,
                subject: trim((string) ($base['subject'] ?? '')) !== ''
                    ? 'Re: '.preg_replace('/^\s*(re|fwd?|fw)\s*:\s*/i', '', (string) $base['subject'])
                    : 'Your project enquiry — '.$vendorName,
                body: $htmlBody,
                emailTemplateName: 'auto-missing-info',
                inReplyToMessageId: $base['rfc_message_id'] ?? null,
            );

            Log::channel('nylas')->info('Crew leads: asked sender for missing info', [
                'lead_id' => $lead->id,
                'missing' => $missing,
            ]);
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Crew leads: missing-info request failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download the enquiry's real attachments onto the lead.
     *
     * Images and PDFs only — those carry drawings, bid forms and photos;
     * calendar invites and signature clutter don't. Inline parts (logos,
     * tracking pixels) are already excluded by the is_inline flag. The
     * shared_from parameter is what lets the download work at all: crew@ has
     * no grant of its own, same story as fetch().
     *
     * @return array<int, array{path:string, name:string, mime:string, size:int}>
     */
    protected function storeAttachments(array $message, array $base, Lead $lead): array
    {
        $attachments = array_values(array_filter(
            (array) ($message['attachments'] ?? []),
            // Images, PDFs — and Word documents: bid-request FORMS arrive as
            // .docx and carry the contact details the email body leaves out
            // (Kathy Moseler's phone lived only in hers).
            fn ($a) => ($a['is_inline'] ?? false) === false
                && preg_match(
                    '#^(image/|application/pdf|application/msword|application/vnd\.openxmlformats-officedocument\.wordprocessingml)#i',
                    (string) ($a['content_type'] ?? '')
                )
                && (int) ($a['size'] ?? 0) <= 25 * 1024 * 1024,
        ));

        $stored = [];
        // Filename → bytes from the message's raw MIME, fetched once and only
        // if the per-attachment endpoint fails. That endpoint rejects
        // `shared_from` outright ("invalid path"), so for the crew@ shared
        // mailbox every direct download 400s — the raw MIME (which the
        // messages endpoint DOES proxy) is the only way to the bytes.
        $mimeBytes = null;

        foreach (array_slice($attachments, 0, 10) as $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $response = Http::withToken(config('nylas.api_key'))
                ->timeout(120)
                ->retry(2, 2000, throw: false)
                ->get(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/') . "/v3/grants/{$base['grant_id']}/attachments/{$id}/download", [
                    'message_id' => $base['nylas_message_id'],
                    'shared_from' => $base['mailbox'],
                ]);

            $name = trim((string) ($attachment['filename'] ?? '')) ?: 'attachment';
            $bytes = ($response->successful() && $response->body() !== '') ? $response->body() : null;

            if ($bytes === null) {
                $mimeBytes ??= $this->rawMimeAttachmentContents($base);
                $bytes = $mimeBytes[$name] ?? null;
            }

            if ($bytes === null) {
                Log::channel('nylas')->warning('Crew leads: attachment download failed', [
                    'lead_id' => $lead->id,
                    'attachment_id' => $id,
                    'status' => $response->status(),
                ]);

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = sprintf(
                'leads/%d/%s%s',
                $lead->id,
                Str::uuid(),
                $extension !== '' ? '.' . $extension : '',
            );

            \Illuminate\Support\Facades\Storage::disk('files')->put($path, $bytes);

            $stored[] = [
                'path' => $path,
                'name' => Str::limit($name, 120, ''),
                'mime' => strtolower((string) ($attachment['content_type'] ?? 'application/octet-stream')),
                'size' => strlen($bytes),
            ];
        }

        return $stored;
    }

    /**
     * Every attachment's bytes keyed by filename, pulled from the message's
     * base64url raw MIME. One request for the whole email — heavier than a
     * per-attachment download, but it works through `shared_from`.
     *
     * @return array<string, string>
     */
    protected function rawMimeAttachmentContents(array $base): array
    {
        $response = Http::withToken(config('nylas.api_key'))
            ->timeout(180)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/') . "/v3/grants/{$base['grant_id']}/messages/{$base['nylas_message_id']}", [
                'shared_from' => $base['mailbox'],
                'fields' => 'raw_mime',
            ]);

        $encoded = (string) $response->json('data.raw_mime');

        if (! $response->successful() || $encoded === '') {
            return [];
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true) ?: base64_decode($encoded);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $contents = [];
            foreach (\Opcodes\MailParser\Message::fromString($raw)->getAttachments() as $part) {
                $filename = trim((string) $part->getFilename());
                if ($filename !== '') {
                    $contents[$filename] = $part->getContent();
                }
            }

            return $contents;
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Crew leads: raw MIME parse failed', [
                'message_id' => $base['nylas_message_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Addresses on the enquiry that belong to the enquirers — every CC except
     * the sender and our own mailboxes.
     *
     * @return array<int, string>
     */
    protected function partnerEmails(array $base): array
    {
        $ours = CompanyEmail::query()
            ->withoutGlobalScopes()
            ->pluck('email')
            ->push((string) config('nylas.crew_leads.mailbox'))
            ->filter()
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->all();

        $from = mb_strtolower((string) ($base['from_email'] ?? ''));

        return collect($base['recipients']['cc'] ?? [])
            ->merge($base['recipients']['to'] ?? [])
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->reject(fn (string $email) => $email === $from || in_array($email, $ours, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Stable dedupe identity, hashed to fit `leads.external_id` (varchar 64).
     *
     * The RFC Message-ID is preferred: Graph ids are not guaranteed stable
     * across folder moves, and the RFC id also matches if the same mail is
     * ever read through a different grant.
     */
    protected function externalId(array $message, array $base): string
    {
        $rfc = $this->headerMap($message)['message-id'] ?? null;

        return $rfc
            ? sha1(strtolower(trim($rfc)))
            : sha1('nylas:' . $base['nylas_message_id']);
    }

    /** Prefer a plain-text part; fall back to stripping the HTML body. */
    protected function plainBody(array $message): string
    {
        $body = (string) ($message['body'] ?? '');
        if ($body === '') {
            return (string) ($message['snippet'] ?? '');
        }

        if (! Str::contains($body, '<')) {
            return trim($body);
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Only look at mail newer than the last run.
     *
     * On the very first run there is no watermark, so the lookback window
     * applies instead — deploying must not import years of archived mail as
     * fresh leads.
     */
    protected function since(): \DateTimeInterface
    {
        $stored = cache()->get('crew_leads:watermark');
        if ($stored) {
            // Small overlap: provider timestamps are not perfectly ordered and
            // the ingest ledger makes re-reads free.
            return now()->setTimestamp((int) $stored)->subMinutes(10);
        }

        return now()->subDays((int) config('nylas.crew_leads.initial_lookback_days'));
    }

    /** @param array<int, array<string, mixed>> $messages */
    protected function rememberWatermark(array $messages): void
    {
        $latest = 0;
        foreach ($messages as $m) {
            $latest = max($latest, (int) ($m['date'] ?? 0));
        }

        if ($latest > 0) {
            cache()->forever('crew_leads:watermark', $latest);
        }
    }
}
