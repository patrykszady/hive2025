<?php

namespace App\Jobs;

use App\Models\VendorDoc;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class LookupEwccvForVendor implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Number of times the job may be attempted before failing.
     */
    public int $tries = 2;

    /**
     * Wall-clock seconds the scraper is allowed to run.
     *
     * Generous because the underlying Puppeteer + 2Captcha flow can spend
     * 30-90s per vendor solving reCAPTCHA and waiting for the EWCCV React
     * app to populate its Redux store.
     */
    public int $timeout = 900;

    public function __construct(
        public int $vendorDocId,
    ) {}

    /**
     * Prevent duplicate scrapes for the same VendorDoc within 30 minutes.
     */
    public function uniqueId(): string
    {
        return 'ewccv-lookup-vendor-doc-'.$this->vendorDocId;
    }

    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(): void
    {
        /** @var VendorDoc|null $doc */
        $doc = VendorDoc::withoutGlobalScopes()->find($this->vendorDocId);

        if (! $doc) {
            Log::info('LookupEwccvForVendor skipped — VendorDoc no longer exists', [
                'vendor_doc_id' => $this->vendorDocId,
            ]);

            return;
        }

        if ($doc->type !== 'workers') {
            Log::info('LookupEwccvForVendor skipped — not a workers comp doc', [
                'vendor_doc_id' => $this->vendorDocId,
                'type' => $doc->type,
            ]);

            return;
        }

        if (! $doc->vendor_id) {
            Log::warning('LookupEwccvForVendor skipped — missing vendor_id', [
                'vendor_doc_id' => $this->vendorDocId,
            ]);

            return;
        }

        Log::info('LookupEwccvForVendor dispatching scraper', [
            'vendor_doc_id' => $this->vendorDocId,
            'vendor_id' => $doc->vendor_id,
        ]);

        $exitCode = Artisan::call('ewccv:scrape-tracking', [
            '--vendor-id' => [$doc->vendor_id],
            '--belongs-to-vendor-id' => $doc->belongs_to_vendor_id,
        ]);

        if ($exitCode !== 0) {
            Log::warning('LookupEwccvForVendor: scraper exited non-zero', [
                'vendor_doc_id' => $this->vendorDocId,
                'exit_code' => $exitCode,
            ]);
        }
    }
}
