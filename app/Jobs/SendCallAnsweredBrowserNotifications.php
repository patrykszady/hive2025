<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCallAnsweredBrowserNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $callLogId,
        public int $answeredByUserId,
    ) {}

    public function handle(WebPushService $webPush): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog) {
            return;
        }

        $answeredBy = User::find($this->answeredByUserId);

        if (! $answeredBy) {
            return;
        }

        // Get call recipients from vendor options
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        if (empty($recipientUserIds)) {
            return;
        }

        // Only notify admins who did NOT answer the call
        $otherAdminIds = array_filter($recipientUserIds, fn ($id) => (int) $id !== $this->answeredByUserId);

        if (empty($otherAdminIds)) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $otherAdminIds)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $callerLabel = $this->resolveCallerDisplayName($callLog);
        $answeredByName = trim($answeredBy->first_name) ?: 'Someone';

        $webPush->sendToSubscriptions($subscriptions, [
            'title' => "{$answeredByName} Answered",
            'body' => "Call from {$callerLabel} was picked up by {$answeredByName}",
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'tag' => "incoming-call-{$callLog->id}",
            'data' => [
                'url' => '/calls',
                'type' => 'call_answered',
                'call_log_id' => $callLog->id,
            ],
        ], 'telnyx');
    }

    protected function resolveCallerDisplayName(CallLog $callLog): string
    {
        if ($callLog->caller_name) {
            return $callLog->display_caller_name;
        }

        $fromNumber = $callLog->from_number;

        if (! $fromNumber) {
            return 'Unknown caller';
        }

        $digits = preg_replace('/\D+/', '', $fromNumber);
        $lastTen = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        if (strlen($lastTen) === 10) {
            return '(' . substr($lastTen, 0, 3) . ') ' . substr($lastTen, 3, 3) . '-' . substr($lastTen, 6);
        }

        return $fromNumber;
    }
}
