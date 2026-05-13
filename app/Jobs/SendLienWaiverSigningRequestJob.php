<?php

namespace App\Jobs;

use App\Enums\LienWaiverStatus;
use App\Mail\LienWaiverSigningRequest;
use App\Models\LienWaiver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendLienWaiverSigningRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $lienWaiverId) {}

    public function handle(): void
    {
        $waiver = LienWaiver::withoutGlobalScopes()
            ->with(['vendor.users', 'project', 'payerVendor'])
            ->find($this->lienWaiverId);

        if (! $waiver) {
            return;
        }

        $recipientEmail = $waiver->vendor?->business_email;
        $recipientName = $waiver->vendor?->short_name ?? $waiver->vendor?->business_name ?? '';

        if (empty($recipientEmail)) {
            $recipient = $waiver->vendor?->users
                ?->filter(fn ($user) => (bool) data_get($user, 'pivot.is_employed') && ! empty($user->email))
                ->first();

            $recipientEmail = $recipient?->email;
            $recipientName = trim((string) ($recipient?->first_name ?? '') . ' ' . (string) ($recipient?->last_name ?? '')) ?: $recipientName;
        }

        if (empty($recipientEmail)) {
            return;
        }

        $mailer = config('mail.default') === 'mailtrap-sdk' ? 'mailtrap-sdk' : null;

        $pending = $mailer
            ? Mail::mailer($mailer)
            : Mail::mailer();

        $pending->to($recipientEmail)->send(
            new LienWaiverSigningRequest($waiver, $recipientName)
        );

        $waiver->forceFill([
            'status' => LienWaiverStatus::Sent,
            'sent_at' => now(),
        ])->saveQuietly();
    }
}
