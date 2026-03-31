<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInboundSmsBrowserNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $messageId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(WebPushService $webPush): void
    {
        $message = SmsMessage::with('thread.client.users')->find($this->messageId);

        if (! $message || ! $message->isInbound()) {
            return;
        }

        $enabledSubscriptions = PushSubscription::query()
            ->where('sms_inbound_enabled', true)
            ->get();

        if ($enabledSubscriptions->isEmpty()) {
            return;
        }

        $fromLabel = $this->resolveSenderDisplayName($message);
        $body = trim($message->display_text ?: 'New message received');

        $basePayload = [
            'title' => "New Text from {$fromLabel}",
            'body' => mb_substr($body, 0, 180),
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'tag' => "sms-thread-{$message->thread_id}-message-{$message->id}",
            'data' => [
                'url' => $message->thread_id
                    ? "/messages?threadId={$message->thread_id}"
                    : '/messages',
            ],
            'requireInteraction' => false,
        ];

        // Group by user so we can include a per-user unread badge count
        $grouped = $enabledSubscriptions->groupBy('user_id');

        foreach ($grouped as $userId => $userSubscriptions) {
            $user = User::find($userId);
            $vendorId = $user?->vendor?->id;
            $badgeCount = SmsGroupThread::unreadCountForUser((int) $userId, $vendorId);

            $webPush->sendToSubscriptions($userSubscriptions, array_merge($basePayload, [
                'badgeCount' => $badgeCount,
            ]), 'telnyx');
        }
    }

    protected function resolveSenderDisplayName(SmsMessage $message): string
    {
        $fromNumber = $message->from_number;

        if (! $fromNumber) {
            return 'Unknown sender';
        }

        $fromDigits = $this->normalizePhone($fromNumber);
        $fromLastTen = strlen($fromDigits) > 10 ? substr($fromDigits, -10) : $fromDigits;

        // 1) Search all users by cell_phone
        $user = User::where('cell_phone', $fromDigits)
            ->orWhere('cell_phone', $fromLastTen)
            ->when(strlen($fromLastTen) === 10, fn ($q) => $q->orWhere('cell_phone', '1' . $fromLastTen))
            ->first();

        if ($user) {
            $fullName = trim($user->first_name . ' ' . $user->last_name);
            if ($fullName !== '') {
                return $fullName;
            }
        }

        // 2) Search vendors by business_phone
        $vendor = Vendor::where('business_phone', $fromDigits)
            ->orWhere('business_phone', $fromLastTen)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $vendor->short_name;
        }

        // 3) Try client users on this thread
        $clientUsers = $message->thread?->client?->users;

        if ($clientUsers) {
            $matchedUser = $clientUsers->first(function ($u) use ($fromDigits, $fromLastTen) {
                $userDigits = $this->normalizePhone($u->cell_phone);
                if ($userDigits === '') {
                    return false;
                }
                $userLastTen = strlen($userDigits) > 10 ? substr($userDigits, -10) : $userDigits;
                return $userDigits === $fromDigits || $userLastTen === $fromLastTen;
            });

            if ($matchedUser) {
                $fullName = trim($matchedUser->first_name . ' ' . $matchedUser->last_name);
                if ($fullName !== '') {
                    return $fullName;
                }
            }
        }

        // 4) Format as (XXX) XXX-XXXX
        if (strlen($fromLastTen) === 10) {
            return '(' . substr($fromLastTen, 0, 3) . ') ' . substr($fromLastTen, 3, 3) . '-' . substr($fromLastTen, 6);
        }

        return $fromNumber;
    }

    protected function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
