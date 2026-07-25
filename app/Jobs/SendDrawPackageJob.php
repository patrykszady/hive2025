<?php

namespace App\Jobs;

use App\Enums\LienWaiverStatus;
use App\Mail\DrawPackageMail;
use App\Models\LienWaiver;
use App\Models\SwornStatement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the draw package (GCSS + GC waiver, one merged PDF) to the user who
 * created the draw, then marks BOTH documents Sent — the flip happens only
 * after the mail actually went out, mirroring the sub-waiver job.
 */
class SendDrawPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $swornStatementId, public int $recipientUserId) {}

    public function handle(): void
    {
        $statement = SwornStatement::query()->find($this->swornStatementId);
        $recipient = User::query()->find($this->recipientUserId);

        if (! $statement || ! $recipient || empty($recipient->email)) {
            return;
        }

        $mailer = config('mail.default') === 'mailtrap-sdk' ? 'mailtrap-sdk' : null;

        ($mailer ? Mail::mailer($mailer) : Mail::mailer())
            ->to($recipient->email)
            ->send(new DrawPackageMail(
                $statement->id,
                trim((string) ($recipient->first_name ?? '') . ' ' . (string) ($recipient->last_name ?? '')),
            ));

        if ($statement->status !== LienWaiverStatus::Signed) {
            $statement->forceFill([
                'status' => LienWaiverStatus::Sent,
                'sent_at' => now(),
            ])->save();
        }

        // The GC's own waiver rode along in the same email.
        LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('vendor_id', $statement->belongs_to_vendor_id)
            ->whereNull('deleted_at')
            ->where('status', LienWaiverStatus::Draft)
            ->get()
            ->each(fn ($waiver) => $waiver->forceFill([
                'status' => LienWaiverStatus::Sent,
                'sent_at' => now(),
            ])->saveQuietly());
    }
}
