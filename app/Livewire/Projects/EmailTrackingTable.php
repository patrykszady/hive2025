<?php

namespace App\Livewire\Projects;

use App\Models\EmailTracking;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithPagination;

class EmailTrackingTable extends Component
{
    use WithPagination;

    #[Reactive]
    public $clientId = null;
    #[Reactive]
    public $projectId = null;
    #[Reactive]
    public $leadId = null;
    protected string $pageName = 'email_page';

    public function updatingClientId(): void
    {
        $this->resetPage($this->pageName);
    }

    public function updatingProjectId(): void
    {
        $this->resetPage($this->pageName);
    }

    public function updatingLeadId(): void
    {
        $this->resetPage($this->pageName);
    }

    #[Computed]
    public function emailTrackingEvents()
    {
        $allEvents = EmailTracking::with('project')
            ->where(function ($query) {
                // Internal/vendor-facing sends stay out of the client-facing
                // tracking card.
                $query->whereNull('email_template_name')
                    ->orWhereNotIn('email_template_name', ['Vendor Payment', 'Lien Waiver Signing Request', 'Draw Package']);
            })
            ->when($this->projectId, function ($query) {
                $query->where('project_id', $this->projectId);
            })
            ->when($this->leadId, function ($query) {
                $query->where('lead_id', $this->leadId);
            })
            ->when(! $this->projectId && ! $this->leadId && $this->clientId, function ($query) {
                $query->whereHas('project', function ($q) {
                    $q->where('client_id', $this->clientId);
                });
            })
            ->orderBy('event_at', 'DESC')
            ->get();

        $allEmails = $allEvents->pluck('recipient_emails')->flatten()->unique()->values()->all();
        $usersByEmail = User::query()->whereIn('email', $allEmails)->get()->keyBy('email');

        // Build a set of vendor team member emails so opens from team members can be
        // excluded from display (only client opens matter).
        $vendorIds = $allEvents->pluck('belongs_to_vendor_id')->filter()->unique()->values()->all();
        $vendorTeamEmails = collect();
        if (! empty($vendorIds)) {
            $vendorTeamEmails = User::query()
                ->whereHas('vendors', fn ($q) => $q->whereIn('vendors.id', $vendorIds))
                ->whereNotNull('email')
                ->pluck('email')
                ->map(fn (string $email): string => strtolower(trim($email)))
                ->filter(fn (string $email): bool => $email !== '')
                ->unique()
                ->values();
        }

        $excludedRecipientEmails = collect((array) config('email_tracking.excluded_recipients', []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values();

        $sentCandidatesByProjectAndTemplate = $allEvents
            ->where('event_type', 'sent')
            ->groupBy(fn ($event) => (string) $event->project_id . '|' . (string) $event->email_template_name);

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

            if (! $event->project_id || ! is_string($event->email_template_name) || $event->email_template_name === '') {
                continue;
            }

            if (! $event->event_at) {
                continue;
            }

            $recipientEmails = is_array($event->recipient_emails) ? $event->recipient_emails : [];
            $recipientEmails = collect($recipientEmails)->filter(fn ($email) => is_string($email) && $email !== '')->values()->all();
            if (empty($recipientEmails)) {
                continue;
            }

            $candidates = $sentCandidatesByProjectAndTemplate->get((string) $event->project_id . '|' . (string) $event->email_template_name, collect());
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
                    if (! $hasRecipient) {
                        return false;
                    }

                    return $sent->event_at->lte($event->event_at);
                })
                ->sortByDesc('event_at')
                ->first();

            if (! $best || ! $best->event_at) {
                continue;
            }

            if ($event->event_at->diffInSeconds($best->event_at) > $inferenceWindowSeconds) {
                continue;
            }

            $inferredSentIdByEventId[$event->id] = (int) $best->id;
        }

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
            ->map(function ($threadEvents) use ($usersByEmail, $vendorTeamEmails, $excludedRecipientEmails) {
                $repliedEvent = $threadEvents->firstWhere('event_type', 'replied');
                $mainEvent = $repliedEvent ?? $threadEvents->first();

                $sentEvent = $threadEvents->firstWhere('event_type', 'sent');
                $sentMetadata = is_array($sentEvent?->metadata) ? $sentEvent->metadata : [];
                $ignoreEmails = collect(array_filter([
                    is_string($sentMetadata['from_email'] ?? null) ? (string) $sentMetadata['from_email'] : null,
                    is_string($sentMetadata['sender_email'] ?? null) ? (string) $sentMetadata['sender_email'] : null,
                ]))
                    ->filter(fn ($email) => is_string($email) && trim($email) !== '')
                    ->map(fn ($email) => strtolower(trim($email)))
                    ->unique()
                    ->values()
                    ->all();

                $shouldIgnoreRecipient = function ($email) use ($ignoreEmails, $vendorTeamEmails, $excludedRecipientEmails): bool {
                    if (! is_string($email) || trim($email) === '') {
                        return true;
                    }

                    $email = strtolower(trim($email));

                    if ($excludedRecipientEmails->contains($email)) {
                        return true;
                    }

                    if (in_array($email, $ignoreEmails, true)) {
                        return true;
                    }

                    if ($vendorTeamEmails->contains($email)) {
                        return true;
                    }

                    return false;
                };

                $threadRecipientEmails = $threadEvents
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();

                $mainEventType = (string) ($mainEvent->event_type ?? '');
                $eventRecipientEmails = $threadEvents
                    ->where('event_type', $mainEventType)
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();

                $users = collect($eventRecipientEmails)
                    ->map(fn ($email) => $usersByEmail->get($email))
                    ->filter()
                    ->values();

                $eventCount = $threadEvents->where('event_type', $mainEventType)
                    ->filter(function ($event) use ($shouldIgnoreRecipient) {
                        $emails = is_array($event->recipient_emails) ? $event->recipient_emails : [];
                        $validEmails = collect($emails)->reject($shouldIgnoreRecipient);

                        return $validEmails->isNotEmpty();
                    })
                    ->count();

                $mainEvent->recipient_users = $users;
                $mainEvent->all_recipient_emails = ! empty($eventRecipientEmails) ? $eventRecipientEmails : $threadRecipientEmails;
                $mainEvent->event_count = $eventCount;

                return $mainEvent;
            })
            ->sortByDesc('event_at')
            ->values();

        $collapsed = collect();
        $current = null;

        foreach ($events as $event) {
            if (! $current) {
                $current = $event;
                continue;
            }

            $canCollapseOpened = ($current->event_type === 'opened')
                && ($event->event_type === 'opened')
                && ((int) $current->project_id === (int) $event->project_id)
                && ((string) $current->email_template_name === (string) $event->email_template_name);

            if (! $canCollapseOpened) {
                $collapsed->push($current);
                $current = $event;
                continue;
            }

            $currentCount = (int) ($current->event_count ?? 1);
            $eventCount = (int) ($event->event_count ?? 1);
            $current->event_count = $currentCount + $eventCount;

            $mergedEmails = collect(array_merge(
                is_array($current->all_recipient_emails ?? null) ? $current->all_recipient_emails : [],
                is_array($event->all_recipient_emails ?? null) ? $event->all_recipient_emails : [],
            ))
                ->filter(fn ($email) => is_string($email) && $email !== '')
                ->unique()
                ->values()
                ->all();

            $current->all_recipient_emails = $mergedEmails;

            $mergedUsers = collect();
            foreach ([$current->recipient_users ?? null, $event->recipient_users ?? null] as $users) {
                if (! $users || ! $users instanceof \Illuminate\Support\Collection) {
                    continue;
                }

                foreach ($users as $user) {
                    if (! $user || ! isset($user->email) || ! is_string($user->email)) {
                        continue;
                    }

                    $mergedUsers->put($user->email, $user);
                }
            }

            $current->recipient_users = $mergedUsers->values();
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
        return view('livewire.projects.email-tracking-table');
    }

    public function placeholder()
    {
        return view('livewire.projects.email-tracking-table-placeholder');
    }
}
