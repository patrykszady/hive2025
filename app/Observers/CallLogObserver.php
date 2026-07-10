<?php

namespace App\Observers;

use App\Models\AppNotification;
use App\Models\CallLog;
use App\Models\Vendor;

class CallLogObserver
{
    /**
     * On status transition into MISSED or VOICEMAIL, create AppNotifications
     * for every admin recipient so the call surfaces in /notifications.
     *
     * Voicemail browser-push is dispatched separately by the
     * call.recording.saved webhook (which sets has_voicemail=true).
     */
    public function updated(CallLog $callLog): void
    {
        if (! $callLog->wasChanged('status')) {
            return;
        }

        $newStatus = $callLog->status;
        $oldStatus = $callLog->getOriginal('status');

        if ($newStatus === $oldStatus) {
            return;
        }

        if (! in_array($newStatus, [CallLog::STATUS_MISSED, CallLog::STATUS_VOICEMAIL], true)) {
            return;
        }

        $vendor = Vendor::find(1);
        $recipientUserIds = (array) data_get($vendor?->options ?? [], 'call_recipients', []);
        $recipientUserIds = array_values(array_unique(array_map('intval', $recipientUserIds)));

        if (empty($recipientUserIds)) {
            return;
        }

        $callerLabel = $callLog->display_caller_name
            ?: $callLog->from_number
            ?: 'Unknown caller';

        $isVoicemail = $newStatus === CallLog::STATUS_VOICEMAIL;
        $title = $isVoicemail
            ? "Voicemail from {$callerLabel}"
            : "Missed call from {$callerLabel}";
        $body = $isVoicemail
            ? 'Tap to listen to the recording.'
            : 'No one was available to answer.';

        foreach ($recipientUserIds as $userId) {
            AppNotification::create([
                'user_id' => $userId,
                'type' => $isVoicemail ? 'voicemail_received' : 'missed_call',
                'title' => $title,
                'body' => $body,
                'action_url' => route('notifications.index'),
                'data' => [
                    'call_log_id' => $callLog->id,
                    'from_number' => $callLog->from_number,
                    'caller_name' => $callLog->caller_name,
                    'status' => $newStatus,
                ],
            ]);
        }
    }
}
