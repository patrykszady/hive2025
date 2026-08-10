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

        // The sidebar badge listens for this — without it the count sits
        // stale until the next full page load (the Messages sidebar refreshes
        // this way too).
        $this->dispatch('notification-read');
    }

    public function markAllAsRead(): void
    {
        AppNotification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->dispatch('notification-read');
    }

    public function goToNotification(int $notificationId): mixed
    {
        $notification = AppNotification::where('user_id', auth()->id())
            ->findOrFail($notificationId);

        $notification->markAsRead();
        $this->dispatch('notification-read');

        if ($notification->action_url) {
            return $this->redirect($notification->action_url, navigate: true);
        }

        return null;
    }

    #[Computed]
    public function notifications()
    {
        // Pure reverse-chronological: the page is grouped by DAY, and
        // unread-first ordering would tear days apart. Unread still stands
        // out by highlight, not position.
        return AppNotification::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * The page's shape: days, newest first, each holding rows where
     * identical notifications (same type + title + body — two missed calls
     * from the same person, say) collapse into one entry with a count.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array{notification: AppNotification, count: int, ids: array<int, int>, unread: bool}>>
     */
    #[Computed]
    public function grouped()
    {
        return $this->notifications->getCollection()
            ->groupBy(fn (AppNotification $n) => $n->created_at->toDateString())
            ->map(fn ($day) => $day
                ->groupBy(fn (AppNotification $n) => $n->type.'|'.$n->title.'|'.$n->body)
                ->map(fn ($group) => [
                    'notification' => $group->first(),
                    'count' => $group->count(),
                    'ids' => $group->pluck('id')->all(),
                    'unread' => $group->contains(fn (AppNotification $n) => ! $n->isRead()),
                ])
                ->values());
    }

    /** Mark every notification in a collapsed group read. */
    public function markGroupAsRead(array $ids): void
    {
        AppNotification::where('user_id', auth()->id())
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->dispatch('notification-read');
    }

    /** Open a collapsed group: the whole group reads, the newest one leads. */
    public function goToGroup(array $ids): mixed
    {
        $newest = AppNotification::where('user_id', auth()->id())
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->firstOrFail();

        $this->markGroupAsRead($ids);

        if ($newest->action_url) {
            return $this->redirect($newest->action_url, navigate: true);
        }

        return null;
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
