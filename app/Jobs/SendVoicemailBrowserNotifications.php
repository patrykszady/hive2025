<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\Vendor;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Notify all admin recipients (vendor.options.call_recipients) when a new
 * voicemail is recorded — both an in-app notification (AppNotification)
 * and a browser push.
 */
class SendVoicemailBrowserNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $callLogId)
    {
    }

    public function handle(WebPushService $webPush): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog || ! $callLog->has_voicemail) {
            return;
        }

        $vendor = Vendor::find(1);
        $recipientUserIds = (array) data_get($vendor?->options ?? [], 'call_recipients', []);
        $recipientUserIds = array_values(array_unique(array_map('intval', $recipientUserIds)));

        if (empty($recipientUserIds)) {
            Log::channel('telnyx')->info('Voicemail notification: no call_recipients configured', [
                'call_log_id' => $callLog->id,
            ]);

            return;
        }

        $callerLabel = $callLog->caller_name
            ?: $callLog->from_number
            ?: 'Unknown caller';

        $title = "New Voicemail from {$callerLabel}";
        $body = 'Tap to listen to the recording.';
        $actionUrl = route('notifications.index');

        // ── In-app notifications (powers /notifications view) ──
        foreach ($recipientUserIds as $userId) {
            AppNotification::create([
                'user_id' => $userId,
                'type' => 'voicemail_received',
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'data' => [
                    'call_log_id' => $callLog->id,
                    'from_number' => $callLog->from_number,
                    'caller_name' => $callLog->caller_name,
                ],
            ]);
        }

        // ── Browser push to every subscription of every recipient ──
        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $recipientUserIds)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = [
            'title' => $title,
            'body' => $body,
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'tag' => "voicemail-{$callLog->id}",
            'data' => [
                'url' => '/notifications',
                'call_log_id' => $callLog->id,
            ],
            'requireInteraction' => true,
        ];

        $webPush->sendToSubscriptions($subscriptions, $payload, 'telnyx');
    }
}
