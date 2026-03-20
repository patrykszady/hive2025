<?php

namespace App\Livewire\Vendors;

use App\Models\EmailTracking;
use App\Models\Vendor;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class VendorPaymentEmailTrackingTable extends Component
{
    use WithPagination;

    protected string $pageName = 'vendor_payment_email_page';

    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $allEvents = EmailTracking::query()
            ->where('event_type', '!=', 'mailtrap_webhook_received')
            ->where('belongs_to_vendor_id', auth()->user()->vendor->id)
            ->where('email_template_name', 'Vendor Payment')
            ->orderByDesc('event_at')
            ->get();

        $allEmails = $allEvents->pluck('recipient_emails')->flatten()->unique()->values()->all();

        $vendorsByEmail = Vendor::withoutGlobalScopes()
            ->whereIn('business_email', $allEmails)
            ->get(['id', 'business_name', 'business_email'])
            ->mapWithKeys(function (Vendor $vendor): array {
                $email = is_string($vendor->business_email) ? strtolower(trim($vendor->business_email)) : null;

                if (! is_string($email) || $email === '') {
                    return [];
                }

                return [$email => $vendor->name];
            });

        $internalDomains = collect((array) config('email_tracking.internal_domains', []))
            ->filter(fn ($d) => is_string($d) && trim($d) !== '')
            ->map(fn (string $d): string => strtolower(trim($d)))
            ->unique()
            ->values();

        $excludedRecipientEmails = collect((array) config('email_tracking.excluded_recipients', []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values();

        $shouldIgnoreRecipient = function ($email) use ($internalDomains, $excludedRecipientEmails): bool {
            if (! is_string($email) || trim($email) === '') {
                return true;
            }

            $email = strtolower(trim($email));

            if ($excludedRecipientEmails->contains($email)) {
                return true;
            }

            $atPos = strrpos($email, '@');
            if ($atPos !== false && $internalDomains->contains(substr($email, $atPos + 1))) {
                return true;
            }

            return false;
        };

        // Build sent candidates for inference (link webhook events → their originating sent row).
        $sentCandidatesByTemplate = $allEvents
            ->where('event_type', 'sent')
            ->groupBy(fn ($event) => (string) $event->email_template_name);

        $inferredSentIdByEventId = [];
        $inferenceWindowSeconds = 6 * 60 * 60;

        foreach ($allEvents as $event) {
            if ($event->event_type === 'sent') {
                continue;
            }

            $linkedSentId = is_array($event->metadata) ? ($event->metadata['linked_sent_id'] ?? null) : null;
            if (is_numeric($linkedSentId) && (int) $linkedSentId > 0) {
                continue;
            }

            if (! is_string($event->email_template_name) || $event->email_template_name === '' || ! $event->event_at) {
                continue;
            }

            $recipientEmails = collect(is_array($event->recipient_emails) ? $event->recipient_emails : [])
                ->filter(fn ($email) => is_string($email) && $email !== '')
                ->values()
                ->all();

            if (empty($recipientEmails)) {
                continue;
            }

            $candidates = $sentCandidatesByTemplate->get((string) $event->email_template_name, collect());
            if ($candidates->isEmpty()) {
                continue;
            }

            $best = $candidates
                ->filter(function ($sent) use ($recipientEmails, $event) {
                    if (! $sent->event_at) {
                        return false;
                    }

                    $sentRecipients = is_array($sent->recipient_emails) ? $sent->recipient_emails : [];
                    $hasRecipient = (bool) collect($recipientEmails)->first(fn ($email) => in_array($email, $sentRecipients, true));

                    return $hasRecipient && $sent->event_at->lte($event->event_at);
                })
                ->sortByDesc('event_at')
                ->first();

            if (! $best?->event_at) {
                continue;
            }

            if ($event->event_at->diffInSeconds($best->event_at) > $inferenceWindowSeconds) {
                continue;
            }

            $inferredSentIdByEventId[$event->id] = (int) $best->id;
        }

        // Group events by thread (sent row, mailtrap message ID, or message_id).
        $events = $allEvents
            ->groupBy(function ($event) use ($inferredSentIdByEventId) {
                if ($event->event_type === 'sent') {
                    return 'sent:' . $event->id;
                }

                $linkedSentId = is_array($event->metadata) ? ($event->metadata['linked_sent_id'] ?? null) : null;
                if (is_numeric($linkedSentId) && (int) $linkedSentId > 0) {
                    return 'sent:' . (int) $linkedSentId;
                }

                $inferredSentId = $inferredSentIdByEventId[$event->id] ?? null;
                if (is_int($inferredSentId) && $inferredSentId > 0) {
                    return 'sent:' . $inferredSentId;
                }

                $mailtrapMessageId = is_array($event->metadata)
                    ? ($event->metadata['mailtrap_message_id'] ?? null)
                    : null;

                if (is_string($mailtrapMessageId) && $mailtrapMessageId !== '') {
                    return 'mailtrap:' . $mailtrapMessageId;
                }

                return $event->thread_id ?: $event->message_id ?: ('email_tracking:' . $event->id);
            })
            ->map(function ($threadEvents) use ($vendorsByEmail, $shouldIgnoreRecipient) {
                $repliedEvent = $threadEvents->firstWhere('event_type', 'replied');
                $mainEvent = $repliedEvent ?? $threadEvents->first();

                $sentEvent = $threadEvents->firstWhere('event_type', 'sent');
                $sentMetadata = is_array($sentEvent?->metadata) ? $sentEvent->metadata : [];
                $senderEmails = collect(array_filter([
                    is_string($sentMetadata['from_email'] ?? null) ? (string) $sentMetadata['from_email'] : null,
                    is_string($sentMetadata['sender_email'] ?? null) ? (string) $sentMetadata['sender_email'] : null,
                ]))
                    ->filter(fn ($email) => is_string($email) && trim($email) !== '')
                    ->map(fn ($email) => strtolower(trim($email)))
                    ->unique()
                    ->values()
                    ->all();

                $shouldIgnoreWithSender = function ($email) use ($shouldIgnoreRecipient, $senderEmails): bool {
                    if ($shouldIgnoreRecipient($email)) {
                        return true;
                    }

                    if (is_string($email) && in_array(strtolower(trim($email)), $senderEmails, true)) {
                        return true;
                    }

                    return false;
                };

                $mainEventType = (string) ($mainEvent->event_type ?? '');
                $eventRecipientEmails = $threadEvents
                    ->where('event_type', $mainEventType)
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreWithSender)
                    ->unique()
                    ->values()
                    ->all();

                $vendorNames = collect($eventRecipientEmails)
                    ->map(fn ($email) => $vendorsByEmail->get(strtolower(trim($email))))
                    ->filter()
                    ->values();

                $eventCount = $threadEvents->where('event_type', $mainEventType)
                    ->filter(function ($event) use ($shouldIgnoreWithSender) {
                        $emails = is_array($event->recipient_emails) ? $event->recipient_emails : [];

                        return collect($emails)->reject($shouldIgnoreWithSender)->isNotEmpty();
                    })
                    ->count();

                $mainEvent->recipient_vendor_names = $vendorNames;
                $mainEvent->all_recipient_emails = ! empty($eventRecipientEmails) ? $eventRecipientEmails : [];
                $mainEvent->event_count = $eventCount;

                return $mainEvent;
            })
            ->sortByDesc('event_at')
            ->values();

        // Collapse consecutive opened events.
        $collapsed = collect();
        $current = null;

        foreach ($events as $event) {
            if (! $current) {
                $current = $event;

                continue;
            }

            $canCollapse = ($current->event_type === 'opened')
                && ($event->event_type === 'opened')
                && ((string) $current->email_template_name === (string) $event->email_template_name);

            if (! $canCollapse) {
                $collapsed->push($current);
                $current = $event;

                continue;
            }

            $current->event_count = (int) ($current->event_count ?? 1) + (int) ($event->event_count ?? 1);

            $current->all_recipient_emails = collect(array_merge(
                is_array($current->all_recipient_emails ?? null) ? $current->all_recipient_emails : [],
                is_array($event->all_recipient_emails ?? null) ? $event->all_recipient_emails : [],
            ))
                ->filter(fn ($email) => is_string($email) && $email !== '')
                ->unique()
                ->values()
                ->all();

            $mergedNames = collect();
            foreach ([$current->recipient_vendor_names ?? null, $event->recipient_vendor_names ?? null] as $names) {
                $names = $names instanceof \Illuminate\Support\Collection ? $names : collect($names ?? []);
                foreach ($names as $name) {
                    if (is_string($name) && $name !== '') {
                        $mergedNames->push($name);
                    }
                }
            }
            $current->recipient_vendor_names = $mergedNames->unique()->values();
        }

        if ($current) {
            $collapsed->push($current);
        }

        $events = $collapsed;

        $currentPage = $this->getPage($this->pageName);
        $perPage = 10;
        $currentPageItems = $events->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentPageItems,
            $events->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'pageName' => $this->pageName, 'query' => request()->query()]
        );
    }

    public function render()
    {
        return view('livewire.vendors.vendor-payment-email-tracking-table');
    }
}
