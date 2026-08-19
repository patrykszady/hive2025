<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receive TrackMyVendor compliance webhooks.
 *
 * TrackMyVendor is webhook-only — no REST API, no API key. Our endpoint is
 * registered in their Settings → Integrations → Webhook Endpoints, and they
 * push events here as they happen. Nothing to poll, so there is no sync
 * command for this provider.
 *
 * Events (per their integration docs):
 *   contractor.created              a subcontractor was added
 *   contractor.compliance_changed   pass ⇄ fail
 *   contractor.coi_expiring         insurance entered an alert window
 *   contractor.license_expiring     licence entered an alert window
 *   contractor.w9_missing           W-9 removed from file
 *
 * Payload: { event, created_at, data: { vendor_name, expiration_date, days_left, … } }
 * Signed with X-TMV-Signature: HMAC-SHA256 of the raw body.
 */
class TrackMyVendorWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->signatureValid($raw, $request->header('X-TMV-Signature'))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);
        $vendorName = $data['vendor_name'] ?? null;

        Log::info('TrackMyVendor webhook received', [
            'event' => $event,
            'vendor_name' => $vendorName,
            'days_left' => $data['days_left'] ?? null,
        ]);

        $vendor = $this->resolveVendor($vendorName);

        if (! $vendor) {
            Log::warning('TrackMyVendor webhook: no vendor matched', ['vendor_name' => $vendorName]);

            return response()->json(['status' => 'no_vendor_matched']);
        }

        // Only insurance-bearing events change what the Verified column says.
        // Licence and W-9 events are recorded but do not claim coverage.
        $insuranceEvent = in_array($event, [
            'contractor.compliance_changed',
            'contractor.coi_expiring',
        ], true);

        if (! $insuranceEvent) {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        // compliance_changed carries the new state; coi_expiring is a warning
        // that coverage is running out but has not lapsed yet.
        $compliant = match ($event) {
            'contractor.compliance_changed' => $this->truthy($data['compliant'] ?? $data['status'] ?? null),
            'contractor.coi_expiring' => true,
            default => false,
        };

        $docs = VendorDoc::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->whereIn('type', ['workers', 'general'])
            ->get();

        foreach ($docs as $doc) {
            $options = $doc->options ?? [];
            $options['trackmyvendor'] = array_merge($options['trackmyvendor'] ?? [], array_filter([
                'vendor_name' => $vendorName,
                'status' => $compliant ? 'compliant' : 'non_compliant',
                'compliant' => $compliant,
                'last_event' => $event,
                'expiring_in_days' => $data['days_left'] ?? null,
                'expiration_date' => $data['expiration_date'] ?? null,
                'checked_at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null));
            $doc->options = $options;
            // saveQuietly: VendorDocObserver re-queues an EWCCV lookup on any
            // change, and recording an inbound verification must not trigger one.
            $doc->saveQuietly();
        }

        return response()->json([
            'status' => 'ok',
            'vendor_id' => $vendor->id,
            'documents_updated' => $docs->count(),
        ]);
    }

    /**
     * X-TMV-Signature is the HMAC-SHA256 of the raw body. Accept either a bare
     * hex digest or a "sha256=" prefixed one — providers differ, and a missing
     * prefix should not read as tampering.
     */
    private function signatureValid(string $raw, ?string $signature): bool
    {
        $secret = config('services.trackmyvendor.webhook_secret');

        if (! $secret) {
            // No secret configured: tolerate unsigned deliveries locally so the
            // integration can be exercised, never in production.
            if (app()->isProduction()) {
                Log::warning('TrackMyVendor webhook rejected: no signing secret configured');

                return false;
            }

            Log::info('TrackMyVendor webhook accepted UNSIGNED (local, no secret configured)');

            return true;
        }

        if (! $signature) {
            Log::warning('TrackMyVendor webhook rejected: signature header missing');

            return false;
        }

        $provided = strtolower(trim(str_replace('sha256=', '', $signature)));

        if (! hash_equals(hash_hmac('sha256', $raw, $secret), $provided)) {
            Log::warning('TrackMyVendor webhook rejected: signature mismatch');

            return false;
        }

        return true;
    }

    /**
     * Match on business name — TrackMyVendor has no stable id we control, and
     * names differ in punctuation and suffix between the two systems.
     */
    private function resolveVendor(?string $vendorName): ?Vendor
    {
        if (! $vendorName) {
            return null;
        }

        $normalize = function (string $name): string {
            $n = strtoupper($name);
            $n = preg_replace('/\b(INC|LLC|CORP|CORPORATION|CO|COMPANY|LTD|LP)\b/', '', $n);

            return preg_replace('/[^A-Z0-9]/', '', $n) ?: '';
        };

        $target = $normalize($vendorName);

        if ($target === '') {
            return null;
        }

        return Vendor::query()
            ->get()
            ->first(fn (Vendor $v) => $normalize((string) $v->business_name) === $target);
    }

    /** Their payload may express compliance as a bool, "pass"/"fail", or "compliant". */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'pass', 'passing', 'compliant', 'yes'], true);
    }
}
