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

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    /** Rows per page on the standalone index (also the skeleton's row ceiling). */
    public const PER_PAGE = 10;

    public static function placeholderRows(): int
    {
        return self::PER_PAGE;
    }

    /**
     * Column defs — the real header rows and the loading skeleton render from
     * this one array. The scoped variant (project/lead card) drops the Project
     * column, which is implicit there.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(bool $scoped = false, bool $narrow = false): array
    {
        // Width budget, by what each column actually holds:
        //  - Event      fixed-width badge ("Opened x11") — never squeeze it
        //  - Template   the longest values ("Estimate Follow Up") — give it the
        //               slack, it's the only column that truncates in practice
        //  - Recipients a first name plus a "+N" ("Jason +1") — needs little
        //  - Date       "03/23/26", or "2 hours ago" on a project card
        // narrow = embedded in a ~500px card; scoped = project card, where the
        // Project column is implied and its share goes to Template/Recipients.
        // Narrow (a ~480px embedded card, .narrow-table paddings) is budgeted
        // from measured content: Event fits "Opened x11" whole, Recipients
        // fits its own header (the widest label), Date leans on the short
        // format ("44m ago"). Template and Project take the slack — they
        // truncate behind tooltips.
        $columns = [
            ['label' => 'Event', 'width' => ($scoped ? 'w-[24%]' : ($narrow ? 'w-[25%]' : 'w-[18%]')).' min-w-0', 'skeleton' => 'badge'],
            ['label' => 'Template', 'width' => ($scoped ? 'w-[31%]' : ($narrow ? 'w-[21%]' : 'w-[22%]')).' min-w-0', 'skeleton' => 'badge'],
        ];

        if (! $scoped) {
            $columns[] = ['label' => 'Project', 'width' => ($narrow ? 'w-[15%]' : 'w-[24%]').' min-w-0', 'skeletonWidth' => 'w-28'];
        }

        $columns[] = ['label' => 'Recipients', 'width' => ($scoped ? 'w-[21%]' : ($narrow ? 'w-[19%]' : 'w-[22%]')).' min-w-0', 'skeletonWidth' => 'w-20'];
        $columns[] = ['label' => 'Date', 'width' => ($scoped ? 'w-[24%]' : ($narrow ? 'w-[20%]' : 'w-[14%]')).' min-w-0', 'skeletonWidth' => 'w-16'];

        return $columns;
    }

    #[Computed]
    public function emailTrackingEvents()
    {
        $allEvents = EmailTracking::with('project')
            ->clientFacing()
            ->when($this->projectId, function ($query) {
                $query->forProjectAndItsLeads((int) $this->projectId);
            })
            ->when($this->leadId, function ($query) {
                $query->where('lead_id', $this->leadId);
            })
            ->when(! $this->projectId && ! $this->leadId && $this->clientId, function ($query) {
                $query->forClientAndItsLeads((int) $this->clientId);
            })
            ->orderBy('event_at', 'DESC')
            ->get();

        $allEmails = $allEvents->pluck('recipient_emails')->flatten()->unique()->values()->all();
        // Keyed lowercase: the DB matches emails case-insensitively but this
        // PHP lookup doesn't, so a user saved as "Green2746@…" would silently
        // miss events recorded as "green2746@…" and display as a raw address.
        $usersByEmail = User::query()->whereIn('email', $allEmails)->get()
            ->keyBy(fn (User $user) => strtolower((string) $user->email));

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

                // "Replied" is the thread's status only while the reply is the
                // LATEST word. Once we write back on the same thread, the ball
                // has moved and the newer send's chain tells the truth again.
                // (Events arrive sorted newest-first, so firstWhere('sent') is
                // the latest outbound message in the thread.)
                $latestSentAt = $threadEvents->firstWhere('event_type', 'sent')?->event_at;
                if ($repliedEvent && $latestSentAt && $repliedEvent->event_at && $repliedEvent->event_at->lt($latestSentAt)) {
                    $repliedEvent = null;
                }
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

                // The team-email filter can leave nothing (e.g. a test send
                // to yourself) — fall back to the raw recipients rather than
                // rendering an empty cell.
                if (empty($eventRecipientEmails) && empty($threadRecipientEmails)) {
                    $threadRecipientEmails = $threadEvents
                        ->pluck('recipient_emails')
                        ->flatten()
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    $eventRecipientEmails = $threadRecipientEmails;
                }

                $users = collect($eventRecipientEmails)
                    ->map(fn ($email) => $usersByEmail->get(strtolower((string) $email)))
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
        $perPage = self::PER_PAGE;
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

    public function placeholder(array $params = [])
    {
        // Mirror the real card: scoped variants (project/lead) drop the
        // Project column and the 640px table floor (they live in ~500px
        // columns and modals).
        $projectId = $params['projectId'] ?? $params['project-id'] ?? null;
        $leadId = $params['leadId'] ?? $params['lead-id'] ?? null;
        $clientId = $params['clientId'] ?? $params['client-id'] ?? null;
        // Every embedded card (project, lead AND client) renders the collapsed
        // presentation now — only the standalone index paginates.
        $embedded = filled($projectId) || filled($leadId) || filled($clientId);

        $pid = is_numeric($projectId) ? (int) $projectId : null;
        $lid = is_numeric($leadId) ? (int) $leadId : null;
        $cid = is_numeric($clientId) ? (int) $clientId : null;

        $rows = static::placeholderRowsFor(projectId: $pid, leadId: $lid, clientId: $cid);

        // Header + footer parity: the scoped card shows a history toggle once
        // it has older events, and the paginated variants add a footer. Reserve
        // both so the card measures the same before and after loading.
        $threads = static::threadCountFor(projectId: $pid, leadId: $lid, clientId: $cid);

        return view('livewire.projects.email-tracking-table-placeholder', [
            'scoped' => (bool) $projectId,
            'embedded' => $embedded,
            'clientId' => $clientId,
            'rows' => $rows,
            'historyCount' => $embedded ? max($threads - 1, 0) : 0,
            'footer' => ! $embedded && $threads > self::PER_PAGE,
        ]);
    }

    /**
     * Skeleton row count that matches what will actually paint: a COUNT is far
     * cheaper than the query the skeleton stands in for, so no card ever
     * flashes more (or fewer) fake rows than it ends up with. Scoped cards
     * collapse to a single visible row; the paginated variant shows one row
     * per email thread, which each 'sent' event roots.
     */
    public static function placeholderRowsFor(?int $projectId = null, ?int $leadId = null, ?int $clientId = null): int
    {
        // clientFacing(): the skeleton must count exactly what the loaded card
        // renders, or it paints a card that then disappears.
        $query = EmailTracking::query()->clientFacing();

        if ($projectId) {
            $query->forProjectAndItsLeads($projectId);
        } elseif ($leadId) {
            $query->where('lead_id', $leadId);
        } elseif ($clientId) {
            $query->forClientAndItsLeads($clientId);
        } else {
            return static::placeholderRows();
        }

        // Collapsed card: the latest event is the only visible row.
        if ($projectId || $leadId || $clientId) {
            return $query->exists() ? 1 : 0;
        }

        return min(static::threadCountFor(clientId: $clientId), static::placeholderRows());
    }

    /**
     * Roughly how many rows the card will paint: one per email thread, each
     * rooted at a 'sent' event. Scoped cards collapse everything after the
     * latest into the history accordion.
     */
    public static function threadCountFor(?int $projectId = null, ?int $leadId = null, ?int $clientId = null): int
    {
        $query = EmailTracking::query()->clientFacing();

        if ($projectId) {
            $query->forProjectAndItsLeads($projectId);
        } elseif ($leadId) {
            $query->where('lead_id', $leadId);
        } elseif ($clientId) {
            $query->forClientAndItsLeads($clientId);
        }
        // No scope: the standalone index counts everything, which is what
        // decides whether it paginates.

        $threads = (clone $query)->where('event_type', 'sent')->count();

        // Events with no 'sent' of their own still render a row each.
        return $threads > 0 ? $threads : ($query->exists() ? 1 : 0);
    }
}
