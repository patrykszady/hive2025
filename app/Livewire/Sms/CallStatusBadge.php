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
    public bool $showAddParticipantModal = false;
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

        $vendorId = $user->vendor?->id;
        if (! $vendorId) {
            return collect();
        }

        // Get all team members assigned to the same vendor with phone numbers.
        return User::query()
            ->whereHas('vendors', fn ($query) => $query->where('vendors.id', $vendorId))
            ->where('id', '!=', $user->id)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    public function openAddParticipantModal(): void
    {
        $this->showAddParticipantModal = true;
        $this->selectedParticipantId = null;
    }

    public function closeAddParticipantModal(): void
    {
        $this->showAddParticipantModal = false;
        $this->selectedParticipantId = null;
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
        $activeCall = CallLog::query()
            ->where('user_id', $user->id)
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

