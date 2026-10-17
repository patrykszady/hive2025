<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class CallStatusBadge extends Component
{
    use HasCallActions;

    /** Shortest search term that widens the search past the team + call-tied people. */
    private const PARTICIPANT_SEARCH_MIN_LENGTH = 2;

    /** Most results rendered at once (Flux autocomplete options are not free). */
    private const PARTICIPANT_SEARCH_LIMIT = 20;

    public ?int $activeCallId = null;
    public ?string $activeCallStatus = null;
    public ?string $otherNumber = null;
    public ?string $otherPartyDisplay = null;

    /** The "add participant" search box — see participantSearchResults(). */
    public string $participantSearch = '';

    /**
     * The Add to Call modal is open (bound to it, so closing it any way
     * clears this). The search runs only then, never on the page loads and
     * 15 s refreshes that render the badge.
     */
    public bool $addingParticipant = false;

    #[On('call.answered')]
    #[On('call.initiated')]
    #[On('call.status.changed')]
    // The Telnyx webhook broadcasts every lifecycle change over Reverb — the
    // hangup clears this badge the second the carrier reports it.
    #[On('echo-private:sms.notifications,CallStatusChanged')]
    public function updateCallStatus(): void
    {
        $this->refreshActiveCall();
    }

    /**
     * An invite this admin sent was never joined — so far always because
     * answering machine detection called it a voicemail box
     * (TelnyxWebhookController::handleConferenceInviteAmdResult). Broadcast
     * on the shared sms.notifications channel every admin already listens
     * on; only toast the one who actually sent this invite.
     *
     * @param  array<string, mixed>  $event
     */
    #[On('echo-private:sms.notifications,ConferenceInviteDeclined')]
    public function handleConferenceInviteDeclined(array $event): void
    {
        if ((int) ($event['invited_by_user_id'] ?? 0) !== (int) auth()->id()) {
            return;
        }

        $name = (string) ($event['participant_name'] ?? 'They');
        Flux::toast(
            variant: 'warning',
            heading: 'Not Added',
            text: "{$name} didn't pick up — it went to voicemail, so they were not added to the call.",
            duration: 6000,
            position: 'top right',
        );
    }

    /**
     * Who the "add participant" search offers, in priority order: the team
     * roster and anyone already tied to this call are offered as soon as the
     * modal opens; everyone else the company knows (its clients, its
     * vendors, and its vendors' people) — plus a free-typed phone number —
     * only once a search term narrows things down, so this never renders
     * hundreds of options.
     *
     * @return array<int, array{key: string, name: string, kind: string, phone: string}>
     */
    #[Computed]
    public function participantSearchResults(): array
    {
        $user = auth()->user();
        if (! $user || ! $this->activeCallId || ! $this->addingParticipant) {
            return [];
        }

        $callLog = CallLog::find($this->activeCallId);
        if (! $callLog) {
            return [];
        }

        $query = trim($this->participantSearch);
        $hasQuery = mb_strlen($query) >= self::PARTICIPANT_SEARCH_MIN_LENGTH;

        $joinedAdminIds = array_map('intval', (array) ($callLog->metadata['joined_admin_ids'] ?? []));
        $excludedUserIds = array_unique(array_merge([$user->id], $joinedAdminIds));

        $results = collect();
        $seenPhones = collect();
        $seenUserIds = collect();

        $addUser = function (User $candidate, string $kind) use ($results, $seenPhones, $seenUserIds, $excludedUserIds): void {
            if (in_array($candidate->id, $excludedUserIds, true) || $seenUserIds->contains($candidate->id)) {
                return;
            }

            $digits = $this->normalizedPhoneDigits($candidate->cell_phone);
            if (! $digits || $seenPhones->contains($digits)) {
                return;
            }

            $seenUserIds->push($candidate->id);
            $seenPhones->push($digits);
            $results->push([
                'key' => "user:{$candidate->id}",
                'name' => trim((string) $candidate->full_name) ?: $this->formatPhone($candidate->cell_phone),
                'kind' => $kind,
                'phone' => (string) $candidate->cell_phone,
            ]);
        };

        // 1. The team roster — same as the keypad press-9 shortcut.
        foreach ($this->teamRosterUsers() as $candidate) {
            if (! $hasQuery || $this->userMatchesQuery($candidate, $query)) {
                $addUser($candidate, 'Team');
            }
        }

        // 2. People tied to this call: the other party's own client (so a
        //    spouse can be pulled in too), and the project's client when the
        //    call resolves to one.
        foreach ($this->callTiedUsers($callLog) as $candidate) {
            if (! $hasQuery || $this->userMatchesQuery($candidate, $query)) {
                $addUser($candidate, 'Client');
            }
        }

        if ($hasQuery) {
            // 3. Anyone else the company knows: its clients (on any
            //    project), its vendors' people (past employees too) — never
            //    another company's.
            $known = $this->knownUsersQuery()
                ->whereNotNull('cell_phone')
                ->where('cell_phone', '!=', '')
                ->where(function (Builder $knownQuery) use ($query): void {
                    $knownQuery->where('cell_phone', 'like', '%'.$query.'%')
                        ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.mb_strtolower($query).'%']);
                })
                ->orderBy('first_name')
                ->limit(self::PARTICIPANT_SEARCH_LIMIT)
                ->get();

            foreach ($known as $candidate) {
                $addUser($candidate, $candidate->clients()->exists() ? 'Client' : 'Vendor');
            }

            // 4. The company's vendors' own business line.
            $vendors = Vendor::query()
                ->whereNotNull('business_phone')
                ->where('business_phone', '!=', '')
                ->where('business_name', 'like', '%'.$query.'%')
                ->orderBy('business_name')
                ->limit(self::PARTICIPANT_SEARCH_LIMIT)
                ->get();

            foreach ($vendors as $vendor) {
                $digits = $this->normalizedPhoneDigits($vendor->business_phone);
                if (! $digits || $seenPhones->contains($digits)) {
                    continue;
                }

                $seenPhones->push($digits);
                $results->push([
                    'key' => "vendor:{$vendor->id}",
                    'name' => $vendor->short_name ?: $vendor->business_name,
                    'kind' => 'Vendor',
                    'phone' => (string) $vendor->business_phone,
                ]);
            }

            // 5. A free-typed phone number — dialable even if it matches nobody.
            $typedPhone = $this->typedPhoneCandidate($query);
            if ($typedPhone) {
                $digits = $this->normalizedPhoneDigits($typedPhone);
                if ($digits && ! $seenPhones->contains($digits)) {
                    $seenPhones->push($digits);
                    $results->push([
                        'key' => 'number:'.$typedPhone,
                        'name' => $this->formatPhone($typedPhone),
                        'kind' => 'number',
                        'phone' => $typedPhone,
                    ]);
                }
            }
        }

        return $results->take(self::PARTICIPANT_SEARCH_LIMIT)->values()->all();
    }

    public function openAddParticipantModal(): void
    {
        $this->participantSearch = '';
        $this->addingParticipant = true;
        // Actually open the Flux modal — the old bool was never read by Flux,
        // so the button did nothing. Matches the codebase convention
        // ($this->modal('name')->show()).
        $this->modal('add-participant')->show();
    }

    public function closeAddParticipantModal(): void
    {
        $this->participantSearch = '';
        $this->addingParticipant = false;
        $this->modal('add-participant')->close();
    }

    /**
     * Invite a result from participantSearchResults() — identified by its
     * opaque "kind:identifier" key rather than a raw id, since a result may
     * be a User, a vendor's bare business line, or a hand-typed number with
     * no record at all.
     */
    public function inviteParticipant(string $key): void
    {
        if (! $this->activeCallId) {
            return;
        }

        $candidate = collect($this->participantSearchResults)->firstWhere('key', $key);
        if (! $candidate || ! $candidate['phone']) {
            return;
        }

        [$type, $identifier] = array_pad(explode(':', $key, 2), 2, null);
        $participantId = $type === 'user' ? (int) $identifier : null;

        $this->inviteParticipantToCall(
            $this->activeCallId,
            $participantId,
            $candidate['phone'],
            $candidate['name'],
        );

        $this->closeAddParticipantModal();
    }

    public function mount(): void
    {
        $this->refreshActiveCall();
    }

    /**
     * The GS admin roster — the same list the phone "press 9" shortcut and
     * the inbound simulring use (Vendor 1's call_recipients). Matching them
     * keeps "who can I pull in" identical across the UI and the keypad, and
     * works for admins who have no vendor of their own (the old
     * same-vendor query returned an empty list and hid the button for them).
     * withoutGlobalScopes: the canonical GS vendor (1) is a fixed config
     * lookup, but this runs in an authenticated context where VendorScope
     * filters vendors to the viewer's own — which would hide vendor 1 (and
     * empty the picker) for any admin who has a vendor of their own.
     *
     * @return Collection<int, User>
     */
    protected function teamRosterUsers(): Collection
    {
        $vendor = Vendor::withoutGlobalScopes()->find(1);
        $recipientIds = array_map('intval', (array) data_get($vendor?->options ?? [], 'call_recipients', []));

        return User::query()
            ->whereIn('id', $recipientIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * The people tied to this specific call: the other party's own client
     * (covers a spouse at the same address) and, when the call resolves to
     * a project, that project's client. Client::query() carries the
     * company's ClientScope, so a client id from another company (or a
     * stale/foreign value) simply never resolves — this can't leak across
     * tenants.
     *
     * @return Collection<int, User>
     */
    protected function callTiedUsers(CallLog $callLog): Collection
    {
        $clientIds = collect([$callLog->client_id]);

        if ($callLog->project_id) {
            $clientIds->push(Project::find($callLog->project_id)?->client?->id);
        }

        $otherUser = $this->otherNumber ? $this->findUserByPhone($this->otherNumber) : null;
        if ($otherUser) {
            $clientIds = $clientIds->merge($otherUser->clients()->pluck('clients.id'));
        }

        $scopedClientIds = Client::query()->whereIn('id', $clientIds->filter()->unique())->pluck('id');

        if ($scopedClientIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereHas('clients', fn ($q) => $q->whereIn('clients.id', $scopedClientIds))
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();
    }

    /**
     * Every user the signed-in company knows: its own people, the people of
     * the vendors it works with, and its clients' users. Never another
     * company's staff or homeowners. Mirrors
     * App\Livewire\Tasks\TaskCreate::knownUsers().
     *
     * @return Builder<User>
     */
    protected function knownUsersQuery(): Builder
    {
        if (! auth()->user()?->vendor) {
            return User::query()->whereRaw('1 = 0');
        }

        $vendorIds = Vendor::query()->pluck('vendors.id');
        $clientIds = Client::query()->pluck('clients.id');

        return User::query()->where(function ($known) use ($vendorIds, $clientIds): void {
            $known->whereHas('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->whereIn('vendors.id', $vendorIds))
                ->orWhereHas('clients', fn ($clients) => $clients->withoutGlobalScopes()->whereIn('clients.id', $clientIds));
        });
    }

    protected function findUserByPhone(string $phone): ?User
    {
        return User::whereIn('cell_phone', static::phoneVariants($phone))->first();
    }

    protected function userMatchesQuery(User $candidate, string $query): bool
    {
        $needle = mb_strtolower($query);
        $nameHaystack = mb_strtolower(trim((string) $candidate->full_name));
        $digitsNeedle = preg_replace('/\D/', '', $query);

        if (str_contains($nameHaystack, $needle)) {
            return true;
        }

        return $digitsNeedle !== '' && str_contains((string) $candidate->cell_phone, $digitsNeedle);
    }

    /**
     * A hand-typed phone number from the search box — a plain US 10-digit
     * number, an 11-digit number with the leading country code, or an
     * already-E.164 string. Normalised the same way as everywhere else a
     * person types a phone number (GroupSmsService::formatE164).
     */
    protected function typedPhoneCandidate(string $query): ?string
    {
        $digits = preg_replace('/\D/', '', $query);

        if ($digits === '') {
            return null;
        }

        $looksLikeUsNumber = strlen($digits) === 10 || (strlen($digits) === 11 && str_starts_with($digits, '1'));
        $looksLikeE164 = str_starts_with(trim($query), '+') && strlen($digits) >= 10;

        if (! $looksLikeUsNumber && ! $looksLikeE164) {
            return null;
        }

        return GroupSmsService::formatE164($query);
    }

    protected function normalizedPhoneDigits(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }

    private function refreshActiveCall(): void
    {
        $user = auth()->user();

        if (!$user) {
            $this->activeCallId = null;
            $this->activeCallStatus = null;
            $this->otherNumber = null;
            $this->otherPartyDisplay = null;
            return;
        }

        // Find the most recent live call for the authenticated user. A call is
        // only "live" while it has no terminating timestamp and was started
        // recently — this guards against calls that never received a hangup
        // webhook and would otherwise keep the badge lit indefinitely.
        // Match calls the user OWNS (outbound they placed) OR calls they've
        // JOINED as an admin (inbound simulring). On inbound, user_id is the
        // external caller, so the owner-only filter never lit the badge for the
        // admin who actually answered — and the "add participant" button with
        // it. joined_admin_ids holds the int user ids on the conference.
        $activeCall = CallLog::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereJsonContains('metadata->joined_admin_ids', $user->id);
            })
            ->whereIn('status', CallLog::ACTIVE_STATUSES)
            ->whereNull('ended_at')
            ->where('created_at', '>=', now()->subMinutes(CallLog::STALE_ACTIVE_MINUTES))
            ->orderByDesc('created_at')
            ->first();

        if ($activeCall) {
            $this->activeCallId = $activeCall->id;
            $this->activeCallStatus = $activeCall->status;

            // Determine the other party's number
            if ($activeCall->direction === 'incoming') {
                $this->otherNumber = $activeCall->from_number;
            } else {
                $this->otherNumber = $activeCall->to_number;
            }

            // Resolve the display name: check for contact name, then CNAM, then phone
            if ($this->otherNumber) {
                $this->otherPartyDisplay = $this->resolvePhoneDisplay($this->otherNumber);
            } else {
                $this->otherPartyDisplay = null;
            }
        } else {
            $this->activeCallId = null;
            $this->activeCallStatus = null;
            $this->otherNumber = null;
            $this->otherPartyDisplay = null;
        }
    }

    public function render()
    {
        return view('livewire.sms.call-status-badge');
    }
}
