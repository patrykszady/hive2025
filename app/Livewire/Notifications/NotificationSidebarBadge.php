<?php

namespace App\Livewire\Notifications;

use App\Models\AppNotification;
use Livewire\Component;

class NotificationSidebarBadge extends Component
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'notification-created' => 'refreshBadge',
            'notification-read' => 'refreshBadge',
        ];
    }

    public function refreshBadge(): void
    {
    }

    public function render()
    {
        $count = 0;

        if (auth()->check()) {
            $count = AppNotification::where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count();
        }

        return view('livewire.notifications.notification-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
