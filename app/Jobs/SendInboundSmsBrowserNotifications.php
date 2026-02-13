<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\SmsMessage;
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
        $body = trim($message->text ?: 'New message received');

        $webPush->sendToSubscriptions($enabledSubscriptions, [
            'title' => "New Text from {$fromLabel}",
            'body' => mb_substr($body, 0, 180),
            'tag' => "sms-thread-{$message->thread_id}-message-{$message->id}",
            'data' => [
                'url' => $message->thread_id
                    ? "/messages?threadId={$message->thread_id}"
                    : '/messages',
            ],
            'requireInteraction' => false,
        ], 'telnyx');
    }

    protected function resolveSenderDisplayName(SmsMessage $message): string
    {
        $fromNumber = $message->from_number;

        if (! $fromNumber) {
            return 'Unknown sender';
        }

        $clientUsers = $message->thread?->client?->users;

        if ($clientUsers) {
            $fromDigits = $this->normalizePhone($fromNumber);
            $fromLastTen = strlen($fromDigits) > 10 ? substr($fromDigits, -10) : $fromDigits;

            $matchedUser = $clientUsers->first(function ($user) use ($fromDigits, $fromLastTen) {
                $userDigits = $this->normalizePhone($user->cell_phone);

                if ($userDigits === '') {
                    return false;
                }

                $userLastTen = strlen($userDigits) > 10 ? substr($userDigits, -10) : $userDigits;

                return $userDigits === $fromDigits || $userLastTen === $fromLastTen;
            });

            if ($matchedUser) {
                $fullName = trim(implode(' ', array_filter([
                    $matchedUser->first_name,
                    $matchedUser->last_name,
                ])));

                if ($fullName !== '') {
                    return $fullName;
                }
            }
        }

        return $fromNumber;
    }

    protected function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
