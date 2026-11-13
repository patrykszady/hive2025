<?php

namespace App\Services\Sms;

use App\Jobs\SendExpoBadgeUpdate;
use App\Models\AppNotification;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Services\Notifications\AppNotificationFeed;

/**
 * Marks one conversation read for one person, wherever they did it: opening
 * it, or swiping it in the Messages list of the website or the Hive
 * Contractors app (Patryk, 2026-11-12: "swipe a message … to mark it as read
 * (remove from notifications)").
 *
 * Read means two things, and both happen here so the three places cannot
 * drift apart: the conversation's unread dot goes (SmsThreadRead moves to
 * its newest message), and the texts from it leave the person's
 * notifications — the bell's rows for that conversation are marked read and
 * their phones' app badge is brought in line. Before this, opening a
 * conversation cleared the dot and left its notifications sitting unread.
 */
class ThreadReadMarker
{
    /**
     * The notification types that belong to one conversation; each carries
     * `data.thread_id` (SendInboundSmsBrowserNotifications,
     * SendOutboundSmsBrowserNotifications).
     *
     * @var list<string>
     */
    public const NOTIFICATION_TYPES = ['sms_received', 'team_message'];

    public function __construct(private AppNotificationFeed $notifications) {}

    /**
     * Marks the conversation read for $user. Returns null when the
     * conversation is not theirs to open, so no caller can mark (or probe)
     * another company's thread.
     *
     * @return array{thread_id: int, was_unread: bool, notifications_cleared: int, unread_notifications: int}|null
     */
    public function markRead(User $user, int $threadId): ?array
    {
        if (! SmsGroupThread::query()->accessibleTo($user)->whereKey($threadId)->exists()) {
            return null;
        }

        $latestMessageId = (int) SmsMessage::query()->where('thread_id', $threadId)->max('id');
        $wasUnread = false;

        if ($latestMessageId > 0) {
            $readUpTo = (int) SmsThreadRead::query()
                ->where('thread_id', $threadId)
                ->where('user_id', $user->id)
                ->value('last_read_message_id');
            $wasUnread = $readUpTo < $latestMessageId;

            if ($wasUnread) {
                SmsThreadRead::query()->updateOrCreate(
                    ['thread_id' => $threadId, 'user_id' => $user->id],
                    ['last_read_message_id' => $latestMessageId],
                );
            }
        }

        $cleared = $this->clearNotifications($user, $threadId);

        return [
            'thread_id' => $threadId,
            'was_unread' => $wasUnread,
            'notifications_cleared' => $cleared,
            'unread_notifications' => $this->notifications->unreadCount((int) $user->id),
        ];
    }

    /**
     * Marks the person's unread notifications about this conversation read
     * and, when any were, sends their phones the new badge number so the
     * app icon does not wait for its next poll. Public for the conversation
     * screen, which has already checked the person may open the thread and
     * moves the read marker itself. Returns how many were cleared.
     */
    public function clearNotifications(User $user, int $threadId): int
    {
        $ids = AppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereIn('type', self::NOTIFICATION_TYPES)
            ->where('data->thread_id', $threadId)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $this->notifications->markIds((int) $user->id, $ids);

        SendExpoBadgeUpdate::dispatch((int) $user->id, $this->notifications->unreadCount((int) $user->id));

        return count($ids);
    }
}
