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
use Illuminate\Support\Facades\Storage;

/**
 * Finish every audit not yet emailed: build the certificates PDF and the
 * Excel, email them to whoever asked, mark it sent. It does not wait for
 * EWCCV: its search needs a person's browser, and nothing may wait on one
 * (Patryk, 2026-10-14), so a check still unanswered reads "not checked on
 * EWCCV" and the certificate on file stands. Dispatched when an audit is
 * opened, and every fifteen minutes as a backstop.
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
        $waiting = AuditRequest::query()
            ->whereIn('status', [AuditRequest::STATUS_PENDING, AuditRequest::STATUS_READY])
            ->whereNull('emailed_at')
            ->orderBy('id')
            ->get();

        foreach ($waiting as $audit) {
            try {
                self::finish($audit, $merger);
            } catch (\Throwable $e) {
                Log::error('Audit package could not be built', ['audit_request_id' => $audit->id, 'error' => $e->getMessage()]);
                $audit->forceFill(['status' => AuditRequest::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            }
        }
    }

    /**
     * Build the package (once), keep it on disk for the audit page's
     * downloads, and email it: links always, the files attached only when
     * they fit under the mail provider's size limit (a year's certificates
     * merged into one PDF exceeded 10 MB on 2026-10-13).
     */
    public static function finish(AuditRequest $audit, CertificateMerger $merger): void
    {
        $report = new AuditReport($audit->belongs_to_vendor_id, $audit->start_date->toDateString(), $audit->end_date->toDateString(), $audit->audit_type, $audit->bank_account_ids, $audit->coverage_date);
        $missing = [];
        $disk = Storage::disk('local');
        $base = 'audits/'.$audit->id.'/'.$report->filenameBase();

        if (! $audit->xlsx_path || ! $disk->exists($audit->xlsx_path)) {
            $disk->put($base.'.xlsx', $report->xlsx());
            $audit->xlsx_path = $base.'.xlsx';
        }
        if (! $audit->pdf_path || ! $disk->exists($audit->pdf_path)) {
            $pdf = $report->certificatesPdf($merger, $missing);
            if ($pdf !== null) {
                $disk->put($base.'.pdf', $pdf);
                $audit->pdf_path = $base.'.pdf';
            }
        }
        $audit->forceFill(['status' => AuditRequest::STATUS_READY, 'ready_at' => $audit->ready_at ?? now(), 'error' => null])->save();

        $recipient = $audit->requestedBy;
        if (! $recipient || blank($recipient->email)) {
            Log::warning('Audit package ready but nobody to email', ['audit_request_id' => $audit->id]);

            return;
        }

        $company = $report->company();
        $companyName = (string) ($company?->name ?? $company?->business_name ?? 'the company');
        $size = ($audit->pdf_path ? $disk->size($audit->pdf_path) : 0) + ($audit->xlsx_path ? $disk->size($audit->xlsx_path) : 0);
        $attach = $size <= AuditRequest::ATTACH_MAX_BYTES;

        try {
            Mail::to($recipient)->send(new AuditReadyMail($audit, $companyName, $report->coverageCounts(), $attach, $missing));
        } catch (\Throwable $e) {
            // The package is built and downloadable; only the mail failed.
            Log::error('Audit package email failed', ['audit_request_id' => $audit->id, 'error' => $e->getMessage()]);
            $audit->forceFill(['error' => 'Email failed: '.mb_substr($e->getMessage(), 0, 900)])->save();

            return;
        }
        $audit->forceFill(['status' => AuditRequest::STATUS_SENT, 'emailed_at' => now(), 'emailed_to' => $recipient->email, 'error' => null])->save();
    }
}
