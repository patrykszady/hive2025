<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Support\Ewccv\ScrapeLauncher;
use App\Support\Ewccv\SearchHints;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Track a vendor's policy on EWCCV from a record EWCCV already returned —
 * the internal policy id, policy number and employer name a coverage check
 * captured — without searching again. A search as of today no longer lists
 * a policy that was cancelled (Smartech, 2026-10-13), yet subscribing to it
 * is exactly what brings the reinstatement notice; and the subscription
 * endpoints take the login alone, so this runs without a browser credential.
 *
 *   ewccv:track-known 737 --written-policy-id=102756218 --policy=WC533SB2784N016 --employer="SMARTECH ELECTRIC INC"
 */
class EwccvTrackKnownRecord extends Command
{
    protected $signature = 'ewccv:track-known
        {vendor : Vendor id}
        {--written-policy-id= : EWCCV\'s internal id of the policy (writtenPolicyId from a lookup log or coverage check)}
        {--policy= : The policy number as EWCCV lists it}
        {--employer= : The employer name as EWCCV lists it}
        {--fein= : The FEIN, if EWCCV returned one}
        {--belongs-to-vendor-id=1 : The tenant asking}
        {--no-run : Record it, start nothing}';

    protected $description = 'Subscribe to a vendor\'s workers comp policy on EWCCV from a record EWCCV returned before, without a new search';

    public function handle(ScrapeLauncher $launcher): int
    {
        $vendor = Vendor::withoutGlobalScopes()->find((int) $this->argument('vendor'));
        if (! $vendor) {
            $this->error('No such vendor.');

            return self::FAILURE;
        }
        foreach (['written-policy-id', 'policy', 'employer'] as $required) {
            if (blank($this->option($required))) {
                $this->error("--{$required} is required.");

                return self::FAILURE;
            }
        }

        $belongsTo = (int) $this->option('belongs-to-vendor-id');
        $today = Carbon::today();
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $belongsTo)
            ->whereDate('effective_date', '<=', $today)
            ->whereDate('expiration_date', '>=', $today)
            ->orderByDesc('expiration_date')
            ->get();
        if ($docs->isEmpty()) {
            $this->error('No workers comp policy active today on file for this vendor; nothing to track.');

            return self::FAILURE;
        }

        SearchHints::save($vendor, ['known_record' => [
            'writtenPolicyId' => (string) $this->option('written-policy-id'),
            'policyNumber' => (string) $this->option('policy'),
            'employerName' => (string) $this->option('employer'),
            'fein' => (string) ($this->option('fein') ?? ''),
            // The record stands for the certificate on file now; a new
            // certificate (new number) gets a fresh search.
            'for_policy_number' => (string) $docs->first()->number,
        ]]);

        foreach ($docs as $doc) {
            $options = $doc->options ?? [];
            $options['ewccv'] = ['status' => 'pending', 'reason' => 'track_known_record', 'checked_at' => now()->toIso8601String()];
            $doc->options = $options;
            $doc->saveQuietly();
        }
        $this->info(sprintf('Known EWCCV record saved for %s: %s, policy %s (id %s); %d doc(s) queued.', $vendor->business_name, $this->option('employer'), $this->option('policy'), $this->option('written-policy-id'), $docs->count()));

        if ($this->option('no-run')) {
            return self::SUCCESS;
        }

        $pid = $launcher->launch([$vendor->id], $belongsTo);
        $this->info($pid ? "Subscribing now (detached run, pid {$pid}); no browser credential needed." : 'The run could not be started.');

        return $pid ? self::SUCCESS : self::FAILURE;
    }
}
