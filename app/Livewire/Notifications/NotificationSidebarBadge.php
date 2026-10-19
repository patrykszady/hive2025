<?php

namespace App\Livewire\Notifications;

use App\Services\Notifications\AppNotificationFeed;
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
            $count = app(AppNotificationFeed::class)->unreadCount((int) auth()->id());
        }

        return view('livewire.notifications.notification-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
