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
            'message' => ['nullable', 'string', 'max:10000'],
            'availability' => ['nullable'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $externalId = (string) $data['external_id'];
        $externalSource = (string) $data['source'];

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
        $notes = $message !== '' ? mb_substr($message, 0, 250) : null;

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
        ];

        // A lead's address becomes a client record — complete it before the
        // lead is stored, so what lands is whole rather than a bare street.
        $leadData = app(LeadAddressCompleter::class)->complete($leadData);

        $lead = Lead::create([
            'date' => $date,
            'origin' => $externalSource,
            'external_source' => $externalSource,
            'external_id' => $externalId,
            'notes' => $notes,
            'lead_data' => $leadData,
            'user_id' => null,
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => $user->id,
        ]);

        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $vendorId,
            'created_at' => $date,
        ]);

        // The form is open to anyone, and contractors are a magnet for pitches
        // — domain sales, SEO, equipment finance. Record every submission as a
        // lead (so nothing disappears), but only build a contact and client
        // for someone who actually wants work done. Same judgement the shared
        // mailbox already applies; a failed or unsure classification provisions
        // as normal rather than dropping a real enquiry.
        // Short enquiries ("Interior remodel") carry too little to judge, and
        // reading brevity as a pitch would cost a real customer — only longer
        // messages are triaged at all.
        $message = (string) ($leadData['message'] ?? '');

        $verdict = str_word_count($message) < 12
            ? ['is_lead' => null, 'confidence' => 0.0, 'reason' => null]
            : app(CrewLeadEmailService::class)->classify(
                (string) ($leadData['subject'] ?? ''),
                $message,
                (string) ($leadData['email'] ?? ''),
            );

        if ($verdict['is_lead'] === false && $verdict['confidence'] >= 0.8) {
            Log::info('Website lead looks like a solicitation — recorded without provisioning', [
                'lead_id' => $lead->id,
                'reason' => $verdict['reason'],
                'confidence' => $verdict['confidence'],
            ]);
        } else {
            app(LeadContactProvisioner::class)->provision($lead->fresh());
        }

        return response()->json([
            'data' => ['id' => $lead->id],
            'created' => true,
        ], 201);
    }
}
