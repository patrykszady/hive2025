<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use Livewire\Attributes\On;
use Livewire\Component;

class SmsSidebarBadge extends Component
{
    #[On('sms-thread-read')]
    #[On('sms-message-received')]
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
