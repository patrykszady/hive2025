<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use Illuminate\Support\Collection;

/**
 * The web notification bell's business rules (App\Livewire\Notifications\
 * NotificationIndex and NotificationSidebarBadge) — extracted so the Hive
 * Contractors app's Notifications tab can show the exact same count,
 * collapsing and read/unread behaviour without forking it. Both Livewire
 * components now delegate here instead of re-querying AppNotification
 * directly.
 */
class AppNotificationFeed
{
    public function unreadCount(int $userId): int
    {
        return AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Collapses identical notifications (same type + title + body, on the
     * same calendar day) in $notifications into one entry each. Mirrors
     * NotificationIndex::grouped()'s inner groupBy exactly — entries keep
     * $notifications' own order (expected newest first), and two otherwise-
     * identical notifications on different days never collapse together.
     *
     * $timezone picks whose calendar day counts: the web passes none (its
     * page groups and labels by the app timezone, UTC); the app passes
     * America/Chicago so tonight's notifications stay on tonight.
     *
     * @param  Collection<int, AppNotification>  $notifications
     * @return list<array{notification: AppNotification, count: int, ids: list<int>, unread: bool, date: string}>
     */
    public function collapse(Collection $notifications, ?string $timezone = null): array
    {
        $entries = [];
        $indexByKey = [];

        foreach ($notifications as $notification) {
            $date = $this->dayOf($notification, $timezone);
            $key = $date.'|'.$notification->type.'|'.$notification->title.'|'.$notification->body;

            if (array_key_exists($key, $indexByKey)) {
                $i = $indexByKey[$key];
                $entries[$i]['ids'][] = $notification->id;
                $entries[$i]['count']++;
                $entries[$i]['unread'] = $entries[$i]['unread'] || ! $notification->isRead();

                continue;
            }

            $indexByKey[$key] = count($entries);
            $entries[] = [
                'notification' => $notification,
                'count' => 1,
                'ids' => [$notification->id],
                'unread' => ! $notification->isRead(),
                'date' => $date,
            ];
        }

        return $entries;
    }

    /**
     * $notifications grouped by calendar day (newest day first, given a
     * newest-first input), each day's rows collapsed via collapse() —
     * exactly NotificationIndex::grouped()'s shape.
     *
     * @param  Collection<int, AppNotification>  $notifications
     * @return Collection<string, Collection<int, array{notification: AppNotification, count: int, ids: list<int>, unread: bool, date: string}>>
     */
    public function groupedByDay(Collection $notifications): Collection
    {
        return $notifications
            ->groupBy(fn (AppNotification $n) => $n->created_at->toDateString())
            ->map(fn (Collection $day) => collect($this->collapse($day)));
    }

    /** The notification's calendar day (Y-m-d) in $timezone, or in the app timezone when none is given. */
    private function dayOf(AppNotification $notification, ?string $timezone): string
    {
        return $timezone === null
            ? $notification->created_at->toDateString()
            : $notification->created_at->copy()->setTimezone($timezone)->toDateString();
    }

    /** A notification $userId owns, or null — every lookup below goes through this so one user never touches another's row. */
    public function find(int $userId, int $notificationId): ?AppNotification
    {
        return AppNotification::where('user_id', $userId)->find($notificationId);
    }

    /** Marks exactly these ids (still scoped to $userId) read — NotificationIndex::markGroupAsRead(). */
    public function markIds(int $userId, array $ids): void
    {
        AppNotification::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Marks every one of $userId's unread notifications read — NotificationIndex::markAllAsRead(). */
    public function markAllRead(int $userId): void
    {
        AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Marks $notification and every other still-unread row that would
     * collapse into the same entry as it (same user, same calendar day,
     * same type + title + body) — the app's "mark one" action, where the
     * tapped row may represent more than one underlying notification.
     *
     * @return list<int> every id marked read (including $notification's own, if it was unread)
     */
    public function markGroupRead(AppNotification $notification, ?string $timezone = null): array
    {
        $query = AppNotification::where('user_id', $notification->user_id)
            ->where('type', $notification->type)
            ->where('title', $notification->title)
            ->where('body', $notification->body);

        if ($timezone === null) {
            $query->whereDate('created_at', $notification->created_at->toDateString());
        } else {
            $localDay = $notification->created_at->copy()->setTimezone($timezone);
            $query->whereBetween('created_at', [
                $localDay->copy()->startOfDay()->setTimezone(config('app.timezone')),
                $localDay->copy()->endOfDay()->setTimezone(config('app.timezone')),
            ]);
        }

        $siblingIds = $query
            ->whereNull('read_at')
            ->pluck('id')
            ->all();

        if ($siblingIds !== []) {
            $this->markIds($notification->user_id, $siblingIds);
        }

        return $siblingIds;
    }
}
