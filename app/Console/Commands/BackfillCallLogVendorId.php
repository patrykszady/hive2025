<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use Illuminate\Console\Command;

/**
 * call_logs.vendor_id did not exist until this security pass — every row
 * created before it belongs to the one company this system has served so
 * far (see CallLog::LEGACY_OWNER_VENDOR_ID and the "TODO: Multi-vendor
 * support" note in TelnyxWebhookController). Dev refreshes from prod, so
 * this stamps the real prod data instead of a one-off SQL fix.
 *
 * Re-runnable: only touches rows still missing a vendor_id.
 */
class BackfillCallLogVendorId extends Command
{
    protected $signature = 'calls:backfill-vendor-id {--vendor= : Vendor id to assign (defaults to CallLog::LEGACY_OWNER_VENDOR_ID)}';

    protected $description = 'Stamp every call log with no vendor_id with the vendor that owns the shared Telnyx numbers.';

    public function handle(): int
    {
        $vendorId = (int) ($this->option('vendor') ?: CallLog::LEGACY_OWNER_VENDOR_ID);

        $updated = CallLog::whereNull('vendor_id')->update(['vendor_id' => $vendorId]);

        $this->info("Set vendor_id={$vendorId} on {$updated} call log(s) that had none.");

        return self::SUCCESS;
    }
}
