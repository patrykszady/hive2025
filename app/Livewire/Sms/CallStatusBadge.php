<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\CallLog;
use App\Services\Calls\ConferenceParticipantSearch;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class CallStatusBadge extends Component
{
    use HasCallActions;

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

        // The candidate priority order (team roster, call-tied people, then
        // everyone the company knows plus a typed number once the query
        // narrows things down) lives in App\Services\Calls\
        // ConferenceParticipantSearch, shared with the app's
        // CallParticipantController so both offer the same people.
        return app(ConferenceParticipantSearch::class)
            ->search($user, $callLog, $this->participantSearch);
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
