<?php

namespace App\Console\Commands;

use App\Jobs\RunPendingEwccvLookups;
use App\Models\EwccvCoverageCheck;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Two days before a workers comp certificate expires, ask EWCCV whether the
 * policy is still in force on that day. A policy EWCCV tracks says so by
 * itself; one it never matched (M Power Solution, expired 10/08/2026 with a
 * last attempt in September) would otherwise lapse without a final answer.
 * The check waits for the next browser credential like every other search,
 * and its answer shows as the Verified date on the doc.
 */
class EwccvExpiringChecks extends Command
{
    protected $signature = 'ewccv:expiring-checks
        {--belongs-to-vendor-id=1 : The tenant asking}
        {--days-before=2 : Check coverage as of this many days before expiration}
        {--window=3 : Also catch docs whose check day was within the last N days (a missed run)}
        {--dry-run : List what would be queued}';

    protected $description = 'Queue an EWCCV coverage check as of two days before expiration for every workers comp doc about to expire';

    public function handle(): int
    {
        $belongsTo = (int) $this->option('belongs-to-vendor-id');
        $daysBefore = max(0, (int) $this->option('days-before'));
        $window = max(0, (int) $this->option('window'));
        $today = Carbon::today();

        // Docs whose check day (expiration − days-before) is today or was in
        // the last $window days, and that have not expired yet.
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $belongsTo)
            ->whereNotNull('vendor_id')
            ->whereDate('expiration_date', '>=', $today)
            ->whereDate('expiration_date', '<=', $today->copy()->addDays($daysBefore + $window))
            ->orderBy('expiration_date')
            ->with(['vendor' => fn ($query) => $query->withoutGlobalScopes()])
            ->get();

        $queued = 0;
        foreach ($docs as $doc) {
            $asOf = $doc->expiration_date->copy()->subDays($daysBefore);
            if ($asOf->gt($today)) {
                continue;
            }
            $label = sprintf('%s — %s expires %s, check as of %s', $doc->vendor?->business_name ?? "vendor {$doc->vendor_id}", $doc->number, $doc->expiration_date->format('m/d/Y'), $asOf->format('m/d/Y'));
            if ($this->option('dry-run')) {
                $this->line("  would queue: {$label}");
                $queued++;

                continue;
            }
            if (EwccvCoverageCheck::request((int) $doc->vendor_id, $belongsTo, $asOf->toDateString())) {
                $this->line("  queued: {$label}");
                $queued++;
            }
        }

        $this->info("Expiring workers comp docs: {$docs->count()}; coverage checks queued: {$queued}.");

        if ($queued > 0 && ! $this->option('dry-run') && BridgedSession::canSearch()) {
            RunPendingEwccvLookups::dispatch();
            $this->info('A browser session is on file: the run starts now.');
        }

        return self::SUCCESS;
    }
}
