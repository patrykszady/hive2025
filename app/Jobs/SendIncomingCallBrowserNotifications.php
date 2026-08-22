<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendIncomingCallBrowserNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $callLogId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(WebPushService $webPush): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog || $callLog->direction !== 'incoming') {
            return;
        }

        // Get call recipients from vendor options
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        if (empty($recipientUserIds)) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $recipientUserIds)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $callerLabel = $this->resolveCallerDisplayName($callLog);

        // Send TWO notifications with distinct tags. Rationale: on iOS, the
        // native phone UI ("GS Construction" / business number) overlaps the
        // first browser notification, hiding the actual caller. A second
        // notification surfaces above/after the iOS card so the recipient can
        // see who is really calling.
        $basePayload = [
            'body' => 'Incoming phone call',
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'data' => [
                // /calls is not a route — the calls list is a TAB on
                // /messages, and ?callId= opens this specific call. Clicking
                // the notification used to land on a 404.
                'url' => '/messages?activeTab=calls&callId='.$callLog->id,
                'type' => 'incoming_call',
                'call_log_id' => $callLog->id,
            ],
            'requireInteraction' => true,
        ];

        $webPush->sendToSubscriptions($subscriptions, $basePayload + [
            'title' => "{$callerLabel} is Calling",
            'tag' => "incoming-call-{$callLog->id}-a",
        ], 'telnyx');

        $webPush->sendToSubscriptions($subscriptions, $basePayload + [
            'title' => "Incoming Call from {$callerLabel}",
            'tag' => "incoming-call-{$callLog->id}-b",
        ], 'telnyx');
    }

    /**
     * Resolve a friendly display name for the caller.
     */
    protected function resolveCallerDisplayName(CallLog $callLog): string
    {
        // Use the caller name already looked up on the call log
        if ($callLog->caller_name) {
            return $callLog->display_caller_name;
        }

        $fromNumber = $callLog->from_number;

        if (! $fromNumber) {
            return 'Unknown caller';
        }

        $digits = preg_replace('/\D+/', '', $fromNumber);
        $lastTen = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        // Search users by phone
        $user = User::where('cell_phone', $digits)
            ->orWhere('cell_phone', $lastTen)
            ->when(strlen($lastTen) === 10, fn ($q) => $q->orWhere('cell_phone', '1' . $lastTen))
            ->first();

        if ($user) {
            $fullName = trim($user->first_name . ' ' . $user->last_name);
            if ($fullName !== '') {
                return $fullName;
            }
        }

        // Search vendors
        $vendor = Vendor::where('business_phone', $digits)
            ->orWhere('business_phone', $lastTen)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $vendor->short_name;
        }

        // Format as (XXX) XXX-XXXX
        if (strlen($lastTen) === 10) {
            return '(' . substr($lastTen, 0, 3) . ') ' . substr($lastTen, 3, 3) . '-' . substr($lastTen, 6);
        }

        return $fromNumber;
    }
}
