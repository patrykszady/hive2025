<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A person invited into a live conference was never actually joined — so
 * far, always because answering machine detection called the leg a
 * voicemail box before it reached the conference. Broadcast on the shared
 * sms.notifications channel (every vendor-side admin already subscribes to
 * it); CallStatusBadge filters to the one admin who sent the invite before
 * toasting, so nobody else sees a notice about an invite that wasn't theirs.
 */
class ConferenceInviteDeclined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $callLogId,
        public int $invitedByUserId,
        public string $participantName,
        public string $reason = 'machine',
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sms.notifications'),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'call_log_id' => $this->callLogId,
            'invited_by_user_id' => $this->invitedByUserId,
            'participant_name' => $this->participantName,
            'reason' => $this->reason,
        ];
    }
}
