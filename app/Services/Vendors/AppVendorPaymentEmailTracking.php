<?php

namespace App\Services\Vendors;

use App\Models\EmailTracking;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * A simplified, read-only version of
 * App\Livewire\Vendors\VendorPaymentEmailTrackingTable for the app: the
 * most recent tracked event (sent/delivered/opened/clicked/replied/
 * bounced) per email thread, for the given template(s) — same data source
 * (EmailTracking), grouped by thread_id (falling back to message_id, then
 * the row itself). Unlike the web table, this does NOT infer a thread for
 * an event with no thread_id/message_id by matching recipient + a time
 * window, and does not collapse consecutive "opened" events — both exist
 * there to patch gaps in older data; recent sends always carry a
 * thread_id. The app's screen links to the web for that fuller history.
 *
 * Vendor attribution: the thread's "sent" event's own metadata.vendor_id
 * first (set at send time, so it is never wrong even if a company shares
 * one inbox across businesses), then the recipient's business_email — the
 * web table's further fallback (resolving a personal email to the one
 * vendor a user belongs to) is skipped here as a rare edge case.
 */
class AppVendorPaymentEmailTracking
{
    /**
     * @param  list<string>  $templates
     * @return list<array{event_type: string, template: ?string, vendor_id: ?int, vendor_name: ?string, event_at: ?string, event_count: int}>
     */
    public function recentEvents(Vendor $company, array $templates, ?int $onlyVendorId = null, int $limit = 20): array
    {
        $allEvents = EmailTracking::query()
            ->where('event_type', '!=', 'mailtrap_webhook_received')
            ->where('belongs_to_vendor_id', $company->id)
            ->whereIn('email_template_name', $templates)
            ->orderByDesc('event_at')
            ->get();

        if ($allEvents->isEmpty()) {
            return [];
        }

        $vendorsByEmail = Vendor::withoutGlobalScopes()
            ->whereIn('business_email', $allEvents->pluck('recipient_emails')->flatten()->filter()->unique()->values())
            ->get(['id', 'business_name', 'business_email'])
            ->filter(fn (Vendor $vendor) => filled($vendor->business_email))
            ->keyBy(fn (Vendor $vendor) => strtolower(trim((string) $vendor->business_email)));

        $rows = $allEvents
            ->groupBy(fn (EmailTracking $event) => $event->thread_id ?: ($event->message_id ?: 'row:'.$event->id))
            ->map(function (Collection $threadEvents) use ($vendorsByEmail) {
                $sent = $threadEvents->firstWhere('event_type', 'sent');
                $latest = $threadEvents->sortByDesc('event_at')->first();

                $metaVendorId = is_array($sent?->metadata) && is_numeric($sent->metadata['vendor_id'] ?? null)
                    ? (int) $sent->metadata['vendor_id']
                    : null;

                $resolvedVendorId = $metaVendorId;
                $vendorName = $resolvedVendorId ? Vendor::withoutGlobalScopes()->whereKey($resolvedVendorId)->value('business_name') : null;

                if ($resolvedVendorId === null) {
                    $email = collect($sent?->recipient_emails ?? $latest->recipient_emails ?? [])->first(fn ($e) => is_string($e) && $e !== '');
                    $match = is_string($email) ? $vendorsByEmail->get(strtolower(trim($email))) : null;
                    $resolvedVendorId = $match?->id;
                    $vendorName = $match?->business_name;
                }

                return [
                    'event_type' => (string) $latest->event_type,
                    'template' => $latest->email_template_name,
                    'vendor_id' => $resolvedVendorId,
                    'vendor_name' => $vendorName,
                    'event_at' => $latest->event_at?->toIso8601String(),
                    'event_count' => $threadEvents->count(),
                ];
            })
            ->when($onlyVendorId !== null, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['vendor_id'] === $onlyVendorId))
            ->sortByDesc('event_at')
            ->take($limit)
            ->values();

        return $rows->all();
    }
}
