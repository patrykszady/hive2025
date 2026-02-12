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
        $count = auth()->check()
            ? SmsGroupThread::unreadCountForUser(auth()->id())
            : 0;

        return view('livewire.sms.sms-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
