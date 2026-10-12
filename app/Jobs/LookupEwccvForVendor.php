<?php

namespace App\Jobs;

use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScrapeLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A workers comp doc was added or changed: have EWCCV look its policy up,
 * when a browser session is at hand. The scraper is started detached
 * (ScrapeLauncher), so the job itself takes milliseconds and no deploy
 * waits on it.
 */
class LookupEwccvForVendor implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Number of times the job may be attempted before failing.
     */
    public int $tries = 2;

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

    public function handle(ScrapeLauncher $launcher): void
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

        // EWCCV's search only accepts a credential a person's browser earned
        // (BridgedSession); without one the scraper can only fail. Mark the
        // doc as waiting — the card says what to do — and let
        // RunPendingEwccvLookups pick it up when a session arrives.
        if (! BridgedSession::canSearch()) {
            $options = $doc->options ?? [];
            if (($options['ewccv']['status'] ?? null) !== 'tracking') {
                $options['ewccv'] = ['status' => 'pending', 'reason' => 'needs_browser_session', 'checked_at' => now()->toIso8601String()];
                $doc->options = $options;
                $doc->saveQuietly();
            }
            Log::info('LookupEwccvForVendor: no EWCCV browser session — doc marked pending', [
                'vendor_doc_id' => $this->vendorDocId,
                'vendor_id' => $doc->vendor_id,
            ]);

            return;
        }

        $pid = $launcher->launch([(int) $doc->vendor_id], (int) $doc->belongs_to_vendor_id);

        Log::info('LookupEwccvForVendor: scraper started detached', [
            'vendor_doc_id' => $this->vendorDocId,
            'vendor_id' => $doc->vendor_id,
            'pid' => $pid,
        ]);
    }
}
