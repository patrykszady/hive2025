<?php

namespace App\Services\Notifications;

use App\Jobs\CheckExpoPushReceipts;
use App\Models\PushToken;

/**
 * Sends one Expo push to the Hive Contractors app phones that want it —
 * the app-side twin of WebPushService::sendToSubscriptions(). Every app
 * push, whether it mirrors an AppNotification row (SendExpoPushForNotification)
 * or not (task digests, schedule changes, a teammate's reply on a client
 * thread), goes through here, so the switches the users page sets on each
 * phone (PushToken::$enabled and PushToken::PREFERENCES) are honoured in one
 * place and every send queues the same delayed receipts check that prunes
 * dead tokens.
 */
class AppDevicePush
{
    public function __construct(private ExpoPushClient $expo) {}

    /**
     * Push $message to every switched-on phone of $userIds — with
     * $preference, only to the phones that opted into it. $message is an
     * Expo message without `to`; see ExpoPushClient::send().
     *
     * @param  list<int>  $userIds
     * @param  value-of<PushToken::PREFERENCES>|null  $preference
     * @param  array<string, mixed>  $message
     */
    public function sendToUsers(array $userIds, ?string $preference, array $message): void
    {
        if ($userIds === []) {
            return;
        }

        $tokens = PushToken::query()
            ->whereIn('user_id', $userIds)
            ->when(
                $preference !== null,
                fn ($query) => $query->wanting($preference),
                fn ($query) => $query->enabled(),
            )
            ->pluck('token')
            ->all();

        $this->sendToTokens($tokens, $message);
    }

    /**
     * The low-level send for callers that already picked their phones.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $message
     */
    public function sendToTokens(array $tokens, array $message): void
    {
        if ($tokens === []) {
            return;
        }

        $tickets = $this->expo->send($tokens, $message);

        if ($tickets !== []) {
            CheckExpoPushReceipts::dispatch($tickets)->delay(now()->addMinutes(15));
        }
    }

    /**
     * Everyone with at least one switched-on phone opted into $preference —
     * the app's answer to "which users have a browser with sms_inbound on".
     *
     * @param  value-of<PushToken::PREFERENCES>  $preference
     * @return list<int>
     */
    public function userIdsWanting(string $preference): array
    {
        return PushToken::query()
            ->wanting($preference)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
