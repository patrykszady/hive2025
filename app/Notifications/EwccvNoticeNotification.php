<?php

namespace App\Notifications;

use App\Support\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * EWCCV reported a change on a tracked workers comp policy (cancellation,
 * non-renewal or reinstatement): one text to each admin of the company that
 * hired the sub, with the vendor docs page to act on.
 */
class EwccvNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $vendorName,
        public string $policyNumber,
        public string $noticeDate,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [SmsChannel::get()];
    }

    public function toTelnyx(object $notifiable): string
    {
        $what = match ($this->kind) {
            'cancellation' => 'CANCELLED',
            'nonrenewal' => 'NOT RENEWED',
            'reinstatement' => 'reinstated',
            default => $this->kind,
        };

        return sprintf(
            "EWCCV: %s's workers comp policy %s was %s (%s). %s",
            $this->vendorName,
            $this->policyNumber,
            $what,
            $this->noticeDate,
            url('/vendor_docs'),
        );
    }
}
