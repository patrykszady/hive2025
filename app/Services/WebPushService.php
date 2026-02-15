<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * Send a push notification to specific PushSubscription models.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array{title: string, body: string, tag?: string, data?: array, requireInteraction?: bool}  $payload
     * @param  string  $logChannel
     */
    public function sendToSubscriptions(Collection $subscriptions, array $payload, string $logChannel = 'stack'): void
    {
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = $this->createWebPush();

        if (! $webPush) {
            return;
        }

        $encoded = json_encode($payload);

        foreach ($subscriptions as $pushSubscription) {
            $webPush->queueNotification(
                $this->buildSubscription($pushSubscription),
                $encoded,
            );
        }

        $this->flushAndCleanup($webPush, $logChannel);
    }

    /**
     * Send a push notification to all subscriptions belonging to given user IDs.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @param  array{title: string, body: string, tag?: string, data?: array, requireInteraction?: bool}  $payload
     * @param  string  $logChannel
     */
    public function sendToUsers(Collection|array $userIds, array $payload, string $logChannel = 'stack'): void
    {
        $userIds = collect($userIds);

        if ($userIds->isEmpty()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $userIds)
            ->get();

        $this->sendToSubscriptions($subscriptions, $payload, $logChannel);
    }

    /**
     * Create a configured WebPush instance, or null when VAPID keys are missing.
     */
    protected function createWebPush(): ?WebPush
    {
        $publicKey = config('services.vapid.public_key');
        $privateKey = config('services.vapid.private_key');

        if (! $publicKey || ! $privateKey) {
            Log::warning('VAPID keys not configured, skipping push notifications');

            return null;
        }

        return new WebPush([
            'VAPID' => [
                'subject' => config('services.vapid.subject', config('app.url')),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }

    /**
     * Build a web-push Subscription DTO from our PushSubscription model.
     */
    protected function buildSubscription(PushSubscription $pushSubscription): Subscription
    {
        $contentEncoding = $pushSubscription->content_encoding ?: $this->detectDefaultContentEncoding($pushSubscription->endpoint);

        return Subscription::create([
            'endpoint' => $pushSubscription->endpoint,
            'keys' => [
                'p256dh' => $pushSubscription->p256dh,
                'auth' => $pushSubscription->auth,
            ],
            'contentEncoding' => $contentEncoding,
        ]);
    }

    protected function detectDefaultContentEncoding(string $endpoint): string
    {
        if (str_contains($endpoint, 'notify.windows.com')) {
            return 'aesgcm';
        }

        return 'aes128gcm';
    }

    /**
     * Flush queued notifications and remove expired subscriptions.
     */
    protected function flushAndCleanup(WebPush $webPush, string $logChannel): void
    {
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                Log::channel($logChannel)->debug('Push notification delivered', [
                    'endpoint' => mb_substr($endpoint, 0, 80) . '…',
                ]);

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $endpoint)->delete();
                Log::channel($logChannel)->info('Expired push subscription removed', [
                    'endpoint' => mb_substr($endpoint, 0, 80) . '…',
                ]);
            }

            Log::channel($logChannel)->warning('Push notification failed', [
                'endpoint' => mb_substr($endpoint, 0, 80) . '…',
                'reason' => $report->getReason(),
                'expired' => $report->isSubscriptionExpired(),
                'status' => $report->getResponse()?->getStatusCode(),
            ]);
        }
    }
}
