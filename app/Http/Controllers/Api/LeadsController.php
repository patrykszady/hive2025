<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
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

        app(LeadContactProvisioner::class)->provision($lead->fresh());

        return response()->json([
            'data' => ['id' => $lead->id],
            'created' => true,
        ], 201);
    }
}
