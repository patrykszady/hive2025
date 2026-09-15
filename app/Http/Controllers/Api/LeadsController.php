<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use App\Services\CrewLeadEmailService;
use App\Services\LeadAddressCompleter;
use App\Services\LeadContactProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Accepts contact-form leads from authorized partner sites
 * (e.g. gs.construction) and persists them as Lead rows scoped to
 * the authenticated user's vendor.
 *
 * Auth: Sanctum personal access token. The token's owning user
 * determines `belongs_to_vendor_id` and `created_by_user_id`.
 *
 * Idempotency: dedup on (belongs_to_vendor_id, external_source,
 * external_id). If the caller re-posts the same external_id we
 * return the existing lead id with HTTP 200 and `created: false`.
 */
class LeadsController extends Controller
{
    /**
     * List this vendor's leads, newest first.
     *
     * Exists so gs.construction can mirror leads it did not create itself —
     * enquiries emailed to crew@ are captured here, not on the website, but
     * the site's admin is where that team looks. Scoped to the token's vendor
     * exactly like store(), so a partner token can only ever read its own.
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->user()?->vendor?->id;
        if (! $vendorId) {
            return response()->json([
                'message' => 'Authenticated user is not associated with a vendor.',
            ], 403);
        }

        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:64'],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $leads = Lead::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('external_source', $source))
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('date', '>=', $since))
            ->orderByDesc('date')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json([
            'data' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'external_source' => $lead->external_source,
                'external_id' => $lead->external_id,
                'origin' => $lead->origin,
                'date' => optional($lead->date)->toIso8601String(),
                'notes' => $lead->notes,
                // lead_data is an ArrayObject cast; cast back so the JSON is a
                // plain object rather than a serialised ArrayObject.
                'lead_data' => (array) $lead->lead_data,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendorId = $user?->vendor?->id;
        if (! $vendorId) {
            return response()->json([
                'message' => 'Authenticated user is not associated with a vendor.',
            ], 403);
        }

        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:64'],
            'source' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:32'],
            'zip' => ['nullable', 'string', 'max:16'],
            'subject' => ['nullable', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:20000'],
            'availability' => ['nullable'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'submitted_at' => ['nullable', 'date'],
            // An email enquiry the site read brings what its reader learned:
            // the files by URL, the classifier's extraction and verdict, and
            // the RFC Message-ID to thread our reply under.
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.url' => ['required', 'url', 'max:2000'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
            'attachments.*.mime' => ['nullable', 'string', 'max:120'],
            'attachments.*.size' => ['nullable', 'integer'],
            'extracted' => ['nullable', 'array'],
            'extracted.mailbox' => ['nullable', 'string', 'max:255'],
            'extracted.project_type' => ['nullable', 'string', 'max:255'],
            'extracted.scope_summary' => ['nullable', 'string', 'max:2000'],
            'extracted.timeline' => ['nullable', 'string', 'max:255'],
            'extracted.budget' => ['nullable', 'string', 'max:255'],
            'extracted.cc_emails' => ['nullable', 'array', 'max:10'],
            'extracted.cc_emails.*' => ['email'],
            'extracted.is_lead' => ['nullable', 'boolean'],
            'extracted.confidence' => ['nullable', 'numeric'],
            'extracted.reason' => ['nullable', 'string', 'max:1000'],
            'in_reply_to' => ['nullable', 'string', 'max:998'],
        ]);

        $externalId = (string) $data['external_id'];
        $externalSource = (string) $data['source'];
        $isEmail = $externalSource === (string) config('nylas.crew_leads.external_source', 'crew-email');

        // LeadScope auto-restricts to current vendor, so this is a per-vendor lookup.
        $existing = Lead::query()
            ->where('external_source', $externalSource)
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            return response()->json([
                'data' => ['id' => $existing->id],
                'created' => false,
            ], 200);
        }

        $date = ! empty($data['submitted_at'])
            ? Carbon::parse($data['submitted_at'])
            : now();

        $message = (string) ($data['message'] ?? '');
        $subject = trim((string) ($data['subject'] ?? ''));
        $extracted = (array) ($data['extracted'] ?? []);

        // `leads.notes` is a 255-char headline. An email gets its subject and
        // the scope in a line, like the crew reader wrote; a form lead the
        // start of its message, as before.
        $notes = $subject !== ''
            ? Str::limit(trim($subject.' — '.(($extracted['scope_summary'] ?? null) ?: Str::squish($message))), 250)
            : ($message !== '' ? mb_substr($message, 0, 250) : null);

        $leadData = [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'message' => $message ?: null,
            'availability' => $data['availability'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'utm' => array_filter([
                'source' => $data['utm_source'] ?? null,
                'medium' => $data['utm_medium'] ?? null,
                'campaign' => $data['utm_campaign'] ?? null,
            ]),
            'submitted_at' => $date->toIso8601String(),
        ] + array_filter([
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'subject' => $subject ?: null,
            // Couples write in together and CC each other — the other people
            // on the enquiry, or provisioning has no one else to reach.
            'cc_emails' => $extracted['cc_emails'] ?? null,
            'project_type' => $extracted['project_type'] ?? null,
            'scope_summary' => $extracted['scope_summary'] ?? null,
            'timeline' => $extracted['timeline'] ?? null,
            'budget' => $extracted['budget'] ?? null,
            'source_mailbox' => $extracted['mailbox'] ?? null,
            'extraction_status' => array_key_exists('is_lead', $extracted) ? 'ok' : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        // A lead's address becomes a client record — complete it before the
        // lead is stored, so what lands is whole rather than a bare street.
        $leadData = app(LeadAddressCompleter::class)->complete($leadData);

        // Someone we already know — a client writing "can we set up a time
        // for the bedroom?", a subcontractor whose own basement needs work —
        // is that contact: link the lead to them so the modal shows who they
        // are and provisioning does not mint a twin.
        $email = mb_strtolower(trim((string) ($leadData['email'] ?? '')));
        $knownUser = $email !== ''
            ? \App\Models\User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->first()
            : null;

        $lead = Lead::create([
            'date' => $date,
            // An email enquiry shows as Email whichever reader caught it.
            'origin' => $isEmail ? 'Email' : $externalSource,
            'external_source' => $externalSource,
            'external_id' => $externalId,
            'notes' => $notes,
            'lead_data' => $leadData,
            'user_id' => $knownUser?->id,
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => $user->id,
        ]);

        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $vendorId,
            'created_at' => $date,
        ]);

        // Whatever the enquirer attached — a bid request form, drawings,
        // photos of the damage — is often the substance of the enquiry.
        // Failure is non-fatal: the lead exists either way.
        if (! empty($data['attachments'])) {
            try {
                $files = $this->copyAttachments($lead, $data['attachments']);
                if ($files !== []) {
                    $lead->update(['lead_data' => $leadData + ['attachments' => $files]]);
                }
            } catch (\Throwable $e) {
                Log::warning('Lead intake: attachment copy failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        // The form is open to anyone, and contractors are a magnet for pitches
        // — domain sales, SEO, equipment finance. Record every submission as a
        // lead (so nothing disappears), but only build a contact and client
        // for someone who actually wants work done. Same judgement the shared
        // mailbox already applies; a failed or unsure classification provisions
        // as normal rather than dropping a real enquiry.
        // Short enquiries ("Interior remodel") carry too little to judge, and
        // reading brevity as a pitch would cost a real customer — only longer
        // messages are triaged at all.
        // Someone we already know is never a pitch, however casual the
        // message reads to the classifier (2026-09-15: a returning client's
        // enquiry was filed as a solicitation, unlinked, and her consult could
        // not be booked). Provision without asking the model.
        // An email the site's reader already judged an enquiry is one — that
        // verdict came from the same model on the same text; asking again
        // costs a call and can only disagree by chance.
        $hinted = ($extracted['is_lead'] ?? null) === true;

        $verdict = match (true) {
            $knownUser !== null, str_word_count($message) < 12 => ['is_lead' => null, 'confidence' => 0.0, 'reason' => null],
            $hinted => ['is_lead' => true, 'confidence' => (float) ($extracted['confidence'] ?? 1), 'reason' => $extracted['reason'] ?? null],
            default => app(CrewLeadEmailService::class)->classify($subject, $message, (string) ($leadData['email'] ?? '')),
        };

        if ($verdict['is_lead'] === false && $verdict['confidence'] >= 0.8) {
            Log::info('Website lead looks like a solicitation — recorded without provisioning', [
                'lead_id' => $lead->id,
                'reason' => $verdict['reason'],
                'confidence' => $verdict['confidence'],
            ]);
        } else {
            app(LeadContactProvisioner::class)->provision($lead->fresh());
        }

        // An email enquiry without an address or phone can't be scheduled —
        // ask the sender for exactly what's missing, right away, once. Only
        // when the classifier actually SAID it is an enquiry; a stranger, or
        // a client mid-order, must not be emailed on the strength of nothing.
        if ($isEmail && $hinted) {
            app(CrewLeadEmailService::class)->requestMissingInfo($lead->fresh(), [
                'subject' => $subject,
                'rfc_message_id' => $data['in_reply_to'] ?? null,
            ]);
        }

        return response()->json([
            'data' => ['id' => $lead->id],
            'created' => true,
        ], 201);
    }

    /**
     * Copy the files the site holds for the lead onto this side's files disk,
     * in the shape the lead modal already renders (the crew reader stored
     * its downloads the same way).
     *
     * Only the site we take leads from is fetched from: the URLs come in
     * over the API, and a token must not turn this server into a proxy.
     *
     * @param  array<int, array{url: string, name?: ?string, mime?: ?string, size?: ?int}>  $attachments
     * @return array<int, array{path: string, name: string, mime: string, size: int}>
     */
    protected function copyAttachments(Lead $lead, array $attachments): array
    {
        $allowedHost = parse_url((string) config('services.gsc.url'), PHP_URL_HOST);
        $stored = [];

        foreach (array_slice($attachments, 0, 10) as $attachment) {
            $url = (string) $attachment['url'];

            if ($allowedHost && strcasecmp((string) parse_url($url, PHP_URL_HOST), $allowedHost) !== 0) {
                Log::warning('Lead intake: attachment host refused', ['lead_id' => $lead->id, 'url' => $url]);

                continue;
            }

            $response = Http::timeout(120)->retry(2, 1000, throw: false)->get($url);
            if (! $response->successful() || $response->body() === '') {
                Log::warning('Lead intake: attachment download failed', ['lead_id' => $lead->id, 'url' => $url, 'status' => $response->status()]);

                continue;
            }

            $name = trim((string) ($attachment['name'] ?? '')) ?: (basename((string) parse_url($url, PHP_URL_PATH)) ?: 'attachment');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = sprintf('leads/%d/%s%s', $lead->id, Str::uuid(), $extension !== '' ? '.'.$extension : '');

            Storage::disk('files')->put($path, $response->body());

            $stored[] = [
                'path' => $path,
                'name' => Str::limit($name, 120, ''),
                'mime' => strtolower((string) (($attachment['mime'] ?? null) ?: ($response->header('Content-Type') ?: 'application/octet-stream'))),
                'size' => strlen($response->body()),
            ];
        }

        return $stored;
    }
}
