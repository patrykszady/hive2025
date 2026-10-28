<?php

namespace App\Jobs\Voice;

use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Voice\InternalCalls;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Patryk is calling" on every device where the callee has Hive, with
 * Answer and Decline on the notification itself (the service worker acts
 * on them with Hive closed; iPhone shows no buttons, so a tap opens Hive
 * to its own Answer banner). Goes to the callee alone, whatever their text
 * notification settings.
 */
class SendInternalCallPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $callLogId)
    {
        $this->onQueue('default');
    }

    public function handle(WebPushService $webPush, InternalCalls $calls): void
    {
        $callLog = CallLog::find($this->callLogId);
        if (! $callLog || ($callLog->metadata['type'] ?? null) !== InternalCalls::TYPE || $calls->hasEnded($callLog)) {
            return;
        }

        $caller = User::withoutGlobalScopes()->find($callLog->metadata['caller_user_id'] ?? $callLog->user_id);
        if (! $caller) {
            return;
        }

        $callerName = InternalCalls::displayName($caller);
        $crew = $calls->isCrew($callLog);

        // One push per callee: the Decline link is signed for that person alone.
        foreach (User::withoutGlobalScopes()->whereIn('id', $calls->calleeIds($callLog))->get() as $callee) {
            $subscriptions = PushSubscription::query()->where('user_id', $callee->id)->get();
            if ($subscriptions->isEmpty()) {
                continue;
            }

            $webPush->sendToSubscriptions($subscriptions, [
                'title' => $crew ? "{$callerName} is calling the crew" : "{$callerName} is calling",
                'body' => $crew ? 'Tap Answer to join in Hive.' : 'Tap Answer to take it in Hive.',
                'icon' => '/favicons/icon-192x192.png',
                'badge' => '/favicons/icon-96x96.png',
                'tag' => "internal-call-{$callLog->id}",
                'requireInteraction' => true,
                'actions' => [
                    ['action' => 'answer', 'title' => 'Answer'],
                    ['action' => 'decline', 'title' => 'Decline'],
                ],
                'data' => [
                    'url' => "/messages?incomingCall={$callLog->id}",
                    'answerUrl' => "/messages?answerCall={$callLog->id}",
                    'declineUrl' => InternalCalls::declineUrl($callLog, $callee),
                    'callLogId' => $callLog->id,
                    'callerName' => $callerName,
                    'crew' => $crew,
                ],
            ], 'telnyx');
        }
    }
}
