<?php

namespace App\Jobs;

use App\Mail\RequestInsurance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendVendorDocRequestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $agent_expired_docs;

    protected $vendor;

    protected $requesting_vendor;

    protected $agent_email;

    /**
     * Create a new job instance.
     */
    public function __construct($agent_expired_docs, $vendor, $requesting_vendor, $agent_email)
    {
        $this->agent_expired_docs = $agent_expired_docs;
        $this->requesting_vendor = $requesting_vendor;
        $this->vendor = $vendor;
        $this->agent_email = $agent_email;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $agentEmail = $this->sanitizeEmail($this->agent_email);
        $vendorEmail = $this->sanitizeEmail($this->vendor->business_email);
        $requestingVendorEmail = $this->sanitizeEmail($this->requesting_vendor->business_email);

        $primaryRecipient = $agentEmail ?? $vendorEmail ?? $requestingVendorEmail;

        if ($primaryRecipient === null) {
            Log::warning('SendVendorDocRequestEmail has no deliverable recipients', [
                'vendor_id' => $this->vendor->id,
                'requesting_vendor_id' => $this->requesting_vendor->id,
            ]);

            return;
        }

        $ccRecipients = collect([$vendorEmail, $requestingVendorEmail])
            ->filter()
            ->reject(fn (string $email): bool => $email === $primaryRecipient)
            ->unique()
            ->values()
            ->all();

        Mail::to($primaryRecipient)
            ->cc($ccRecipients)
            ->send(new RequestInsurance($this->agent_expired_docs, $this->vendor, $this->requesting_vendor));
    }

    private function sanitizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $normalizedEmail = trim($email);

        if ($normalizedEmail === '' || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalizedEmail;
    }
}
