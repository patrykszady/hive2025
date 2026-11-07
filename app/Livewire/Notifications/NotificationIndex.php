<?php

namespace App\Livewire\Notifications;

use App\Models\AppNotification;
use App\Models\Project;
use App\Models\Task;
use App\Services\Notifications\AppNotificationFeed;
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
        app(AppNotificationFeed::class)->markAllRead((int) auth()->id());

        $this->dispatch('notification-read');
    }

    public function goToNotification(int $notificationId): mixed
    {
        $notification = AppNotification::where('user_id', auth()->id())
            ->findOrFail($notificationId);

        $notification->markAsRead();
        $this->dispatch('notification-read');

        $url = $this->resolveActionUrl($notification);

        if ($url) {
            return $this->redirect($url, navigate: true);
        }

        return null;
    }

    /**
     * The notification's action_url, resolved for two shapes that can no
     * longer be followed as-is:
     *
     * 1. The legacy bare '/planner' (before the planner gained a real route
     *    and a task deep link) is not a route at all.
     * 2. A link already resolved to a real project or task at creation time,
     *    but whose project has since lost every pending task of its own —
     *    the Krupa bug (Patryk, 2026-11-07): the homeowner used a finished
     *    project's schedule link, so the notification pointed at that empty
     *    project while the actual pending work sat on a sibling job.
     *
     * Both resolve the same way, from the notification's data.project_id:
     * when that project still has a pending task, this is exactly what
     * Task::pendingForServiceAvailability() would have picked when the row
     * was created, so an already-correct link is returned unchanged. When it
     * does not, the click falls back to the same client's project that does
     * (Task::bestServiceAvailabilityProject() — the same rule
     * submitServiceAvailability() itself now saves to), so the Hub
     * notification opens the task that actually needs scheduling even for a
     * row created before the fix (or before the repair command ran). Every
     * other notification type's action_url passes through unchanged; GET
     * /planner itself permanently redirects too, so even a route()-less link
     * to it still lands on the planner, not a 404.
     */
    private function resolveActionUrl(AppNotification $notification): ?string
    {
        $url = $notification->action_url;

        if ($notification->type !== 'service_availability_submitted') {
            return $url;
        }

        $isLegacyBarePlanner = $url === '/planner';

        $project = Project::find((int) data_get($notification->data, 'project_id'));

        if (! $project) {
            return $isLegacyBarePlanner ? route('planner.cards') : $url;
        }

        $task = Task::pendingForServiceAvailability($project);

        if ($task) {
            return $isLegacyBarePlanner ? route('planner.cards', ['task' => $task->id]) : $url;
        }

        $targetProject = Task::bestServiceAvailabilityProject($project);

        if (! $targetProject) {
            return $isLegacyBarePlanner ? route('projects.show', $project) : $url;
        }

        $targetTask = Task::pendingForServiceAvailability($targetProject);

        return $targetTask
            ? route('planner.cards', ['task' => $targetTask->id])
            : route('projects.show', $targetProject);
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
        return app(AppNotificationFeed::class)->groupedByDay($this->notifications->getCollection());
    }

    /** Mark every notification in a collapsed group read. */
    public function markGroupAsRead(array $ids): void
    {
        app(AppNotificationFeed::class)->markIds((int) auth()->id(), $ids);

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

        $url = $this->resolveActionUrl($newest);

        if ($url) {
            return $this->redirect($url, navigate: true);
        }

        return null;
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(AppNotificationFeed::class)->unreadCount((int) auth()->id());
    }

    #[Title('Notifications')]
    public function render()
    {
        return view('livewire.notifications.notification-index');
    }
}
