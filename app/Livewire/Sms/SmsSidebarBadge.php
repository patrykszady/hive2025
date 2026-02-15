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
            if ($user->is_client_user) {
                $clientIds = $user->clients()->pluck('clients.id')->toArray();
                $count = SmsGroupThread::unreadCountForUserInClients($user->id, $clientIds);
            } else {
                $count = SmsGroupThread::unreadCountForUser($user->id);
            }
        }

        return view('livewire.sms.sms-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
