<?php

namespace App\Livewire\Notifications;

use App\Models\AppNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class NotificationIndex extends Component
{
    public function markAsRead(int $notificationId): void
    {
        $notification = AppNotification::where('user_id', auth()->id())
            ->findOrFail($notificationId);

        $notification->markAsRead();
    }

    public function markAllAsRead(): void
    {
        AppNotification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function goToNotification(int $notificationId): mixed
    {
        $notification = AppNotification::where('user_id', auth()->id())
            ->findOrFail($notificationId);

        $notification->markAsRead();

        if ($notification->action_url) {
            return $this->redirect($notification->action_url, navigate: true);
        }

        return null;
    }

    #[Computed]
    public function notifications()
    {
        return AppNotification::where('user_id', auth()->id())
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return AppNotification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->count();
    }

    #[Title('Notifications')]
    public function render()
    {
        return view('livewire.notifications.notification-index');
    }
}
