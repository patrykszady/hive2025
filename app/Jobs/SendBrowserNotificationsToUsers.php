<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A browser notification to every subscribed device of the given users —
 * the push half of an in-app alert (see AdminAlerts). Respects each
 * device's "realtime" preference, the general on/off switch the push
 * settings expose.
 */
class SendBrowserNotificationsToUsers implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $userIds
     * @param  array{title: string, body: string, tag?: string, data?: array, requireInteraction?: bool}  $payload
     */
    public function __construct(
        public array $userIds,
        public array $payload,
        public string $logChannel = 'stack',
    ) {}

    public function handle(WebPushService $webPush): void
    {
        if ($this->userIds === []) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $this->userIds)
            ->where('realtime_enabled', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush->sendToSubscriptions($subscriptions, array_merge([
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'requireInteraction' => false,
        ], $this->payload), $this->logChannel);
    }
}
