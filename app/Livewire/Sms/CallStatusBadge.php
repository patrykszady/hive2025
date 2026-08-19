<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\CallLog;
use App\Models\User;
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
    public ?int $selectedParticipantId = null;

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

    #[Computed]
    public function availableParticipants()
    {
        $user = auth()->user();
        if (! $user || ! $this->activeCallId) {
            return collect();
        }

        // The GS admin roster — the same list the phone "press 9" shortcut and
        // the inbound simulring use (Vendor 1's call_recipients). Matching them
        // keeps "who can I pull in" identical across the UI and the keypad, and
        // works for admins who have no vendor of their own (the old
        // same-vendor query returned an empty list and hid the button for them).
        // withoutGlobalScopes: the canonical GS vendor (1) is a fixed config
        // lookup, but this runs in an authenticated context where VendorScope
        // filters vendors to the viewer's own — which would hide vendor 1 (and
        // empty the picker) for any admin who has a vendor of their own. The
        // webhook path reads it unauthenticated, so it never hit this.
        $vendor = \App\Models\Vendor::withoutGlobalScopes()->find(1);
        $recipientIds = array_map('intval', (array) data_get($vendor?->options ?? [], 'call_recipients', []));

        return User::query()
            ->whereIn('id', $recipientIds)
            ->where('id', '!=', $user->id)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    public function openAddParticipantModal(): void
    {
        $this->selectedParticipantId = null;
        // Actually open the Flux modal — the old bool was never read by Flux,
        // so the button did nothing. Matches the codebase convention
        // ($this->modal('name')->show()).
        $this->modal('add-participant')->show();
    }

    public function closeAddParticipantModal(): void
    {
        $this->selectedParticipantId = null;
        $this->modal('add-participant')->close();
    }

    public function inviteParticipant(): void
    {
        if (!$this->activeCallId || !$this->selectedParticipantId) {
            return;
        }

        $callLog = CallLog::find($this->activeCallId);
        if (!$callLog) {
            return;
        }

        $participant = User::find($this->selectedParticipantId);
        if (!$participant || !$participant->cell_phone) {
            return;
        }

        // Invite the participant to the active conference call
        $this->inviteParticipantToCall(
            $this->activeCallId,
            $this->selectedParticipantId,
            $participant->cell_phone,
            $participant->full_name
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

