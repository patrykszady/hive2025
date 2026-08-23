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

    /**
     * @param  bool  $superseded  True when this send replaces a document the
     *                            vendor already has — the amount changed after
     *                            the first email, so the mail leads with a
     *                            warning that the earlier copy must not be
     *                            signed.
     */
    public function __construct(public int $lienWaiverId, public bool $superseded = false) {}

    public function handle(): void
    {
        $waiver = LienWaiver::withoutGlobalScopes()
            ->with(['vendor.users', 'project', 'payerVendor'])
            ->find($this->lienWaiverId);

        // The queue can outrun the UI: never email a waiver that was deleted,
        // cancelled or signed between dispatch and execution — and never
        // regress a Signed/Cancelled status back to Sent.
        if (! $waiver || $waiver->trashed()) {
            return;
        }

        if (! in_array($waiver->status, [LienWaiverStatus::Draft, LienWaiverStatus::Sent], true)) {
            return;
        }

        $recipientEmail = $waiver->vendor?->business_email;
        $recipientName = $waiver->vendor?->short_name ?? $waiver->vendor?->business_name ?? '';
        $recipientLocale = $waiver->vendor?->preferredLocale() ?? 'en';

        if (empty($recipientEmail)) {
            $recipient = $waiver->vendor?->users
                ?->filter(fn ($user) => (bool) data_get($user, 'pivot.is_employed') && ! empty($user->email))
                ->sortBy('id')
                ->first();

            $recipientEmail = $recipient?->email;
            $recipientName = trim((string) ($recipient?->first_name ?? '') . ' ' . (string) ($recipient?->last_name ?? '')) ?: $recipientName;

            // Only override the vendor-level locale when the user actually has
            // a language preference recorded.
            if ($recipient?->preferred_language) {
                $recipientLocale = $recipient->preferredLocale();
            }
        }

        // Vendor has no email anywhere (typical for material suppliers): send
        // to the GC user who created the waiver with a "please forward to the
        // right contact at {vendor}" banner, so it still goes out by email.
        $forwardVendorName = null;
        if (empty($recipientEmail)) {
            $creator = $waiver->created_by_user_id
                ? \App\Models\User::find($waiver->created_by_user_id)
                : null;

            if ($creator && ! empty($creator->email)) {
                $recipientEmail = $creator->email;
                $recipientName = trim((string) ($creator->first_name ?? '') . ' ' . (string) ($creator->last_name ?? ''));
                $recipientLocale = $creator->preferredLocale();
                $forwardVendorName = $waiver->vendor?->short_name ?? $waiver->vendor?->business_name ?? '';
            }
        }

        if (empty($recipientEmail)) {
            \Illuminate\Support\Facades\Log::warning('Lien waiver signing request skipped — no recipient email on file.', [
                'lien_waiver_id' => $waiver->id,
                'vendor_id' => $waiver->vendor_id,
            ]);

            return;
        }

        $mailer = config('mail.default') === 'mailtrap-sdk' ? 'mailtrap-sdk' : null;

        $pending = $mailer
            ? Mail::mailer($mailer)
            : Mail::mailer();

        // Subject and body render in the recipient's preferred language.
        // (In local/dev/test, Mail::alwaysTo redirects delivery to the dev
        // inbox — see AppServiceProvider — while production mails the vendor.)
        $pending->to($recipientEmail)->locale($recipientLocale)->send(
            new LienWaiverSigningRequest($waiver, $recipientName, $forwardVendorName, $this->superseded)
        );

        $waiver->forceFill([
            'status' => LienWaiverStatus::Sent,
            'sent_at' => now(),
        ])->saveQuietly();
    }
}
