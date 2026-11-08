<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Services\Notifications\AppDevicePush;
use App\Services\Notifications\AppNotificationFeed;
use App\Services\Notifications\AppNotificationTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The single fan-out point from an AppNotification row to a real iOS/
 * Android push via Expo — dispatched off App\Observers\
 * AppNotificationObserver::created() rather than wired into each of the 14
 * existing AppNotification::create() call sites, so no future one has to
 * remember to add push. Every type pushes except menards_browser, which
 * stays in-app-only for the reason documented on
 * NotifyMenardsBrowserNeedsAttention.
 *
 * Which phones get it is AppDevicePush's call, from the switches the users
 * page sets per phone: a phone that is switched off gets nothing, and a
 * client's text (sms_received) only reaches phones with "Text activity"
 * on — the one type a user can opt out of without silencing the phone. A
 * team_message is addressed to this member personally and a missed call,
 * voicemail, lead or signature request is not a text, so those honour the
 * phone's own switch alone.
 *
 * `badge` always comes from AppNotificationFeed::unreadCount() so the OS
 * badge can never drift from the in-app count or the web sidebar's.
 * `data.target` reuses the same AppNotificationTarget classifier the app's
 * own NotificationController already embeds per row, so tapping a push
 * opens exactly what tapping the in-app row would.
 *
 * sms_received/team_message pushes additionally carry Expo's own `threadId`
 * field (iOS-only — visually groups a conversation's pushes in Notification
 * Center) and a `subtitle` naming the conversation, plus a `categoryId` the
 * app can register an interactive action under later. See
 * https://docs.expo.dev/push-notifications/sending-notifications/#message-request-format
 * for the full field list — `threadId` is a real Expo field, not something
 * that has to be faked through `data`.
 */
class SendExpoPushForNotification implements ShouldQueue
{
    use Queueable;

    /** AppNotification types that interrupt even through Focus/Do Not Disturb. */
    private const TIME_SENSITIVE_TYPES = ['missed_call', 'voicemail_received'];

    /** Conversation-shaped types — the ones Expo's threadId/subtitle/categoryId grouping applies to. */
    private const MESSAGE_TYPES = ['sms_received', 'team_message'];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $notificationId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(AppNotificationFeed $feed, AppDevicePush $devices): void
    {
        $notification = AppNotification::find($this->notificationId);

        if (! $notification || $notification->type === 'menards_browser') {
            return;
        }

        $message = [
            'title' => $notification->title,
            'body' => $notification->body,
            'sound' => 'default',
            'badge' => $feed->unreadCount($notification->user_id),
            'interruptionLevel' => in_array($notification->type, self::TIME_SENSITIVE_TYPES, true)
                ? 'time-sensitive' : 'active',
            'data' => [
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'action_url' => $notification->action_url,
                'target' => AppNotificationTarget::for($notification->action_url),
            ],
        ];

        if (in_array($notification->type, self::MESSAGE_TYPES, true)) {
            $message = $this->addConversationGrouping($message, $notification);
        }

        $devices->sendToUsers(
            [(int) $notification->user_id],
            $notification->type === 'sms_received' ? 'sms_inbound_enabled' : null,
            $message,
        );
    }

    /**
     * thread_id is already stored in both sms_received's and team_message's
     * `data` column (see App\Jobs\SendInboundSmsBrowserNotifications and
     * SendOutboundSmsBrowserNotifications::notifyTeam()) — read from there
     * rather than re-parsing action_url, which doesn't carry a thread id
     * for every shape team threads use the same ?threadId=&messageId=
     * query the client-facing ones do, so this one read covers both.
     */
    private function addConversationGrouping(array $message, AppNotification $notification): array
    {
        $message['categoryId'] = 'message';

        $threadId = $notification->data['thread_id'] ?? null;

        if ($threadId === null) {
            return $message;
        }

        $message['threadId'] = (string) $threadId;
        $message['data']['thread_id'] = $threadId;

        if ($subtitle = $this->conversationName($threadId, (int) $notification->user_id)) {
            $message['subtitle'] = $subtitle;
        }

        return $message;
    }

    /** The thread's display name: the team thread's title for $userId, or the client's name for a client thread. */
    private function conversationName(int|string $threadId, int $userId): ?string
    {
        $thread = SmsGroupThread::with('client')->find($threadId);

        if (! $thread) {
            return null;
        }

        return $thread->isTeam()
            ? $thread->teamTitleFor(User::find($userId))
            : $thread->client?->name;
    }
}
