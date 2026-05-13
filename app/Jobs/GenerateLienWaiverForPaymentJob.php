<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Support\LienWaiverDocumentGenerator;
use App\Support\LienWaiverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Auto-generate a draft LienWaiver (and its draft PDF) for a newly recorded
 * Payment. Safe to dispatch repeatedly — LienWaiverFactory is idempotent.
 */
class GenerateLienWaiverForPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $paymentId) {}

    public function handle(): void
    {
        $payment = Payment::withoutGlobalScopes()->find($this->paymentId);

        if (! $payment) {
            return;
        }

        $waiver = LienWaiverFactory::fromPayment($payment);

        if (! $waiver) {
            return;
        }

        // Best-effort draft PDF generation. PDF rendering relies on
        // Browsershot / headless Chrome which may not be available in every
        // environment, so failures here must not roll back the waiver record.
        try {
            LienWaiverDocumentGenerator::generate($waiver, store: true);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
