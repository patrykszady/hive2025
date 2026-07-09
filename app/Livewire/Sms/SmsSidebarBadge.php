<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use Livewire\Component;

class SmsSidebarBadge extends Component
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => 'refreshBadge',
            'sms-thread-read' => 'refreshBadge',
            'sms-message-received' => 'refreshBadge',
        ];
    }

    public function refreshBadge(): void
    {
    }

    public function render()
    {
        $user = auth()->user();
        $count = 0;

        if ($user) {
            // Badge counts unread messages newer than the user's last visit to
            // /messages — visiting the page resets the badge without touching
            // per-thread unread indicators.
            $seenAt = $user->sms_last_seen_at ? \Illuminate\Support\Carbon::parse($user->sms_last_seen_at) : null;

            if ($user->is_browsing_as_client) {
                $clientIds = $user->clients()->pluck('clients.id')->toArray();
                $count = SmsGroupThread::unreadCountForUserInClients($user->id, $clientIds, $seenAt);
            } else {
                $vendorId = $user->vendor?->id;
                $count = SmsGroupThread::unreadCountForUser($user->id, $vendorId, $seenAt);
            }
        }

        return view('livewire.sms.sms-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
