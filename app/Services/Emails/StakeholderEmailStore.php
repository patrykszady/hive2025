<?php

namespace App\Services\Emails;

use App\Jobs\SummarizeEmailMessage;
use App\Models\CompanyEmail;
use App\Models\EmailMessage;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keep one Nylas message when it is email with a project stakeholder: who
 * it is with, which project it is about (when that is already certain),
 * the readable body, and the real attachments — not logos, signature art,
 * calendar invites or S/MIME signatures. Everything else (personal mail,
 * internal mail, strangers) is never stored.
 *
 * Called by `emails:sync` for every connected mailbox's inbox and sent
 * folder, and by the Nylas webhook job for instant inbound mail. One email
 * is one row however many inboxes it reached: keyed like the lead readers,
 * by the sha1 of its RFC Message-ID.
 */
class StakeholderEmailStore
{
    public function __construct(
        protected EmailStakeholders $stakeholders,
        protected CrewLeadEmailService $mail,
    ) {
    }

    /**
     * @param  array<string, mixed>  $message  a Nylas v3 message with include_headers
     * @param  bool  $shared  read through another grant with shared_from (crew@): files come from the raw MIME
     */
    public function store(array $message, string $grantId, string $mailbox, bool $shared = false): ?EmailMessage
    {
        if (! config('emails.stakeholder_sync.enabled')) {
            return null;
        }

        $nylasId = (string) ($message['id'] ?? '');
        $fromEmail = mb_strtolower(trim((string) ($message['from'][0]['email'] ?? '')));
        $mailbox = mb_strtolower(trim($mailbox));

        if ($nylasId === '' || $fromEmail === '') {
            return null;
        }

        $vendorId = $this->companyFor($mailbox, $grantId);

        if (! $vendorId) {
            return null;
        }

        $headers = $this->mail->headerMap($message);
        $rfcId = trim((string) ($headers['message-id'] ?? ''));
        $key = $rfcId !== '' ? sha1(strtolower($rfcId)) : sha1('nylas:'.$nylasId);

        if ($existing = EmailMessage::query()->where('belongs_to_vendor_id', $vendorId)->where('message_key', $key)->first()) {
            return $existing;
        }

        $to = $this->addresses($message['to'] ?? []);
        $cc = $this->addresses($message['cc'] ?? []);
        $outbound = $this->mail->isOurAddress($fromEmail);
        $subject = (string) ($message['subject'] ?? '');
        $threadId = $message['thread_id'] ?? null;
        $body = $this->mail->plainBody($message);

        $placement = $this->placementFor($fromEmail, $to, $cc, $subject, $threadId, $vendorId, $body);

        if (! $placement) {
            return null;
        }

        $match = $placement['match'];
        $projectId = $placement['project_id'];
        $candidateIds = $placement['candidate_ids'];

        try {
            $row = EmailMessage::create([
                'belongs_to_vendor_id' => $vendorId,
                'message_key' => $key,
                'nylas_message_id' => $nylasId,
                'grant_id' => $grantId,
                'mailbox' => $mailbox,
                'thread_id' => $threadId,
                'rfc_message_id' => $rfcId !== '' ? Str::limit($rfcId, 990, '') : null,
                'in_reply_to' => isset($headers['in-reply-to']) ? Str::limit(trim($headers['in-reply-to']), 990, '') : null,
                'direction' => $outbound ? EmailMessage::DIRECTION_OUTBOUND : EmailMessage::DIRECTION_INBOUND,
                'from_email' => Str::limit($fromEmail, 255, ''),
                'from_name' => isset($message['from'][0]['name']) ? Str::limit(trim((string) $message['from'][0]['name']), 255, '') : null,
                'to' => $to,
                'cc' => $cc,
                'subject' => Str::limit($subject, 500, ''),
                'snippet' => Str::limit(trim((string) ($message['snippet'] ?? '')) ?: Str::squish($body), 500, ''),
                'body_text' => Str::limit($body, 200000, ''),
                'sent_at' => isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date']) : now(),
                'client_id' => $match['client_id'],
                'vendor_id' => $match['vendor_id'],
                'user_id' => $match['user']?->id,
                'project_id' => $projectId,
                'candidate_project_ids' => $candidateIds,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The webhook and the sweep raced on the same email.
            return EmailMessage::query()->where('belongs_to_vendor_id', $vendorId)->where('message_key', $key)->first();
        }

        $files = $this->storeAttachments($message, $row, $grantId, $mailbox, $shared);

        if ($files !== []) {
            $row->forceFill(['attachments' => $files])->save();
        }

        SummarizeEmailMessage::dispatch($row->id);

        return $row;
    }

    /**
     * Who an email is with and which projects it could be about: everyone
     * outside the company on it, a client the subject names ("Abbey-plumbing
     * delivery"), a project whose street address it names ("3154 VIOLET LN
     * - PB26-0157", from a village nobody on the job is), and the project the
     * rest of its thread is already on. An email naming exactly one
     * project's address goes on that project, whoever sent it.
     * Shared by storing new mail and `emails:rematch-unplaced`.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @return array{match: array{user: ?\App\Models\User, client_id: ?int, vendor_id: ?int, projects: \Illuminate\Support\Collection<int, \App\Models\Project>}, project_id: ?int, candidate_ids: list<int>}|null
     */
    public function placementFor(string $fromEmail, array $to, array $cc, string $subject, ?string $threadId, int $vendorId, ?string $body = null): ?array
    {
        $fromEmail = mb_strtolower(trim($fromEmail));
        $outbound = $this->mail->isOurAddress($fromEmail);

        $external = collect($outbound ? [...$to, ...$cc] : [$fromEmail, ...$to, ...$cc])
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->reject(fn (string $address) => $address === '' || $this->mail->isOurAddress($address))
            ->unique()
            ->values()
            ->all();

        $match = $external === [] ? null : $this->stakeholders->resolve($external, $vendorId);

        // The house the email is about: its subject first, then the new part of its body.
        $addressed = $this->stakeholders->namedByAddress($subject, $vendorId);
        if ($addressed->isEmpty() && $body !== null && $body !== '') {
            $addressed = $this->stakeholders->namedByAddress(mb_substr(SummarizeEmailMessage::latestPart($body), 0, 6000), $vendorId);
        }

        if (! $match) {
            // Nobody on it is a stakeholder: kept only when it names one project's address.
            if ($addressed->count() !== 1) {
                return null;
            }

            $project = $addressed->first();
            $match = ['user' => null, 'client_id' => $project->client_id ? (int) $project->client_id : null, 'vendor_id' => null, 'projects' => $addressed];
        }

        // A project the address names, then a client the subject names, ahead of the rest.
        $projects = $addressed
            ->concat($this->stakeholders->namedInSubject($subject, $vendorId))
            ->concat($match['projects'])
            ->unique('id')
            ->take(EmailStakeholders::MAX_CANDIDATES)
            ->values();

        // A reply goes where the rest of its thread already went.
        $threadProjectId = $this->threadProjectId($vendorId, $threadId);
        $candidateIds = $projects->pluck('id')
            ->when($threadProjectId && ! $projects->contains('id', $threadProjectId), fn ($ids) => $ids->push($threadProjectId))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'match' => $match,
            'project_id' => match (true) {
                $addressed->count() === 1 => (int) $addressed->first()->id,
                $projects->count() === 1 => (int) $projects->first()->id,
                default => $threadProjectId,
            },
            'candidate_ids' => $candidateIds,
        ];
    }

    /** The project an earlier email of the same conversation was put on, if any. */
    public function threadProjectId(int $vendorId, ?string $threadId): ?int
    {
        if (! $threadId) {
            return null;
        }

        $projectId = EmailMessage::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->where('thread_id', $threadId)
            ->whereNotNull('project_id')
            ->latest('id')
            ->value('project_id');

        return $projectId ? (int) $projectId : null;
    }

    /**
     * The attachments a person would call files. Inline parts, calendar
     * invites, vCards, S/MIME signatures, winmail.dat and small images (the
     * logo and social icons in every signature) are left behind.
     *
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    public function storeAttachments(array $message, EmailMessage $row, string $grantId, string $mailbox, bool $shared = false): array
    {
        $worth = array_values(array_filter((array) ($message['attachments'] ?? []), fn ($attachment) => is_array($attachment) && self::isWorthKeeping($attachment)));
        $worth = array_slice($worth, 0, (int) config('emails.stakeholder_sync.attachments.max_per_message', 15));

        if ($worth === []) {
            return [];
        }

        $base = ['grant_id' => $grantId, 'nylas_message_id' => $row->nylas_message_id, 'mailbox' => $mailbox];
        $fromMime = null;
        $stored = [];

        foreach ($worth as $attachment) {
            $name = trim((string) ($attachment['filename'] ?? '')) ?: 'attachment';
            $bytes = null;

            // A shared mailbox's per-attachment download 400s; its raw MIME works.
            if (! $shared && ($id = (string) ($attachment['id'] ?? '')) !== '') {
                $response = Http::withToken((string) config('nylas.api_key'))
                    ->timeout(120)
                    ->retry(2, 2000, throw: false)
                    ->get(rtrim((string) config('nylas.api_uri', 'https://api.us.nylas.com'), '/')."/v3/grants/{$grantId}/attachments/{$id}/download", [
                        'message_id' => $row->nylas_message_id,
                    ]);

                $bytes = ($response->successful() && $response->body() !== '') ? $response->body() : null;
            }

            if ($bytes === null) {
                $fromMime ??= $this->mail->rawMimeAttachmentContents($base);
                $bytes = $fromMime[$name] ?? null;
            }

            if ($bytes === null) {
                Log::channel('nylas')->warning('Stakeholder email: attachment download failed', ['email_message_id' => $row->id, 'name' => $name]);

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = sprintf('emails/%d/%s/%s%s', $row->belongs_to_vendor_id, $row->sent_at->format('Y/m'), Str::uuid(), $extension !== '' ? '.'.$extension : '');

            Storage::disk('files')->put($path, $bytes);

            $stored[] = [
                'path' => $path,
                'name' => Str::limit($name, 180, ''),
                'mime' => strtolower((string) ($attachment['content_type'] ?? 'application/octet-stream')),
                'size' => strlen($bytes),
            ];
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $attachment  a Nylas attachment descriptor
     */
    public static function isWorthKeeping(array $attachment): bool
    {
        if (($attachment['is_inline'] ?? false) === true) {
            return false;
        }

        $name = mb_strtolower(trim((string) ($attachment['filename'] ?? '')));
        $type = mb_strtolower(trim((string) ($attachment['content_type'] ?? '')));
        $size = (int) ($attachment['size'] ?? 0);

        if ($size > (int) config('emails.stakeholder_sync.attachments.max_bytes', 25 * 1024 * 1024)) {
            return false;
        }

        if (preg_match('#^(text/calendar|text/x-vcard|text/vcard|application/(pkcs7|x-pkcs7)-signature|application/ms-tnef|application/ics)#', $type)
            || preg_match('/\.(ics|vcf|p7s|asc|sig)$/', $name)
            || $name === 'winmail.dat') {
            return false;
        }

        if (str_starts_with($type, 'image/')) {
            if (preg_match('/^(image\d{3}|outlook-|logo|signature|sig[_-]|icon|facebook|instagram|linkedin|twitter|youtube|houzz|google)/', $name)) {
                return false;
            }

            return $size === 0 || $size >= (int) config('emails.stakeholder_sync.attachments.min_image_bytes', 15 * 1024);
        }

        return $name !== '' || $type !== '';
    }

    /**
     * @param  mixed  $participants  Nylas [{email, name}] list
     * @return list<string>
     */
    protected function addresses(mixed $participants): array
    {
        return collect((array) $participants)
            ->map(fn ($participant) => mb_strtolower(trim((string) (is_array($participant) ? ($participant['email'] ?? '') : $participant))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** The company whose mailbox this is: a connected inbox, its grant, or a vendor's business address (crew@). */
    protected function companyFor(string $mailbox, string $grantId): ?int
    {
        $vendorId = CompanyEmail::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$mailbox])->value('vendor_id')
            ?? CompanyEmail::withoutGlobalScopes()->where('grant_id', $grantId)->value('vendor_id')
            ?? Vendor::withoutGlobalScopes()->whereRaw('LOWER(business_email) = ?', [$mailbox])->value('id');

        return $vendorId ? (int) $vendorId : null;
    }
}
