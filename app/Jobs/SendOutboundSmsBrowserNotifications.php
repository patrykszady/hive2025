<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOutboundSmsBrowserNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $messageId,
        public int $sentByUserId,
    ) {}

    /**
     * Send browser push notifications to other vendor users when someone replies on a thread.
     */
    public function handle(WebPushService $webPush): void
    {
        $message = SmsMessage::with('thread.client')->find($this->messageId);

        if (! $message || ! $message->isOutbound()) {
            return;
        }

        $sender = User::find($this->sentByUserId);

        if (! $sender) {
            return;
        }

        // Send to all subscriptions with sms_inbound_enabled, excluding the sender
        $enabledSubscriptions = PushSubscription::query()
            ->where('sms_inbound_enabled', true)
            ->where('user_id', '!=', $this->sentByUserId)
            ->get();

        if ($enabledSubscriptions->isEmpty()) {
            return;
        }

        $senderName = trim($sender->first_name . ' ' . $sender->last_name) ?: 'A team member';
        $threadLabel = $this->resolveThreadLabel($message);
        $body = trim($message->text ?: 'Sent a message');

        $webPush->sendToSubscriptions($enabledSubscriptions, [
            'title' => "{$senderName} replied to {$threadLabel}",
            'body' => mb_substr($body, 0, 180),
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'tag' => "sms-outbound-thread-{$message->thread_id}-message-{$message->id}",
            'data' => [
                'url' => $message->thread_id
                    ? "/messages?threadId={$message->thread_id}"
                    : '/messages',
            ],
            'requireInteraction' => false,
        ], 'telnyx');
    }

    /**
     * Resolve a friendly label for the thread (client name, project address, or phone).
     */
    protected function resolveThreadLabel(SmsMessage $message): string
    {
        $thread = $message->thread;

        if ($thread?->client) {
            return $thread->client->name ?? 'a client';
        }

        if ($thread?->project) {
            return $thread->project->address ?? 'a project';
        }

        // Fall back to first participant phone
        $participants = $thread?->participants ?? [];

        if (! empty($participants)) {
            $phone = $participants[0];
            $digits = preg_replace('/\D/', '', $phone);

            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $digits = substr($digits, 1);
            }

            if (strlen($digits) === 10) {
                return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
            }

            return $phone;
        }

        return 'a thread';
    }
}
