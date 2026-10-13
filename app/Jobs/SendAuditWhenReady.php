<?php

namespace App\Jobs;

use App\Mail\AuditReadyMail;
use App\Models\AuditRequest;
use App\Services\Audits\AuditReport;
use App\Services\Audits\CertificateMerger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Finish every audit whose EWCCV answers are in: build the certificates PDF
 * and the Excel, email them to whoever asked, mark it sent. Runs after each
 * EWCCV run records coverage checks and every fifteen minutes as a backstop
 * (an audit with nothing to check is finished at once).
 */
class SendAuditWhenReady implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Merging a year of certificates through iLovePDF takes minutes. */
    public int $timeout = 1500;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(CertificateMerger $merger): void
    {
        $pending = AuditRequest::query()->where('status', AuditRequest::STATUS_PENDING)->orderBy('id')->get();

        foreach ($pending as $audit) {
            if ($audit->pendingChecks() > 0) {
                continue;
            }
            try {
                self::finish($audit, $merger);
            } catch (\Throwable $e) {
                Log::error('Audit package could not be built', ['audit_request_id' => $audit->id, 'error' => $e->getMessage()]);
                $audit->forceFill(['status' => AuditRequest::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            }
        }
    }

    /** Build the package and email it. */
    public static function finish(AuditRequest $audit, CertificateMerger $merger): void
    {
        $report = new AuditReport($audit->belongs_to_vendor_id, $audit->start_date->toDateString(), $audit->end_date->toDateString(), $audit->audit_type, $audit->bank_account_ids, $audit->coverage_date);
        $missing = [];
        $pdf = $report->certificatesPdf($merger, $missing);
        $xlsx = $report->xlsx();
        $company = $report->company();
        $companyName = (string) ($company?->name ?? $company?->business_name ?? 'the company');

        $audit->forceFill(['status' => AuditRequest::STATUS_READY, 'ready_at' => now(), 'error' => null])->save();

        $recipient = $audit->requestedBy;
        if (! $recipient || blank($recipient->email)) {
            Log::warning('Audit package ready but nobody to email', ['audit_request_id' => $audit->id]);

            return;
        }

        Mail::to($recipient)->send(new AuditReadyMail($audit, $companyName, $report->coverageCounts(), $pdf, $xlsx, $report->filenameBase(), $missing));
        $audit->forceFill(['status' => AuditRequest::STATUS_SENT, 'emailed_at' => now(), 'emailed_to' => $recipient->email])->save();
    }
}
