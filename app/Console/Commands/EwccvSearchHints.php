<?php

namespace App\Console\Commands;

use App\Jobs\RunPendingEwccvLookups;
use App\Models\EwccvCoverageCheck;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\SearchHints;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tell the EWCCV lookup what else to search a vendor by, and have it try
 * again with the next browser session.
 *
 *   ewccv:hints 737 --name="Smartech Inc" --address="3548 Grandmore Ave, Gurnee, IL 60031" --check-as-of=2025-06-01
 *
 * A coverage check as of an earlier date (when an older policy was in force
 * at an older address) lists the employer's EWCCV record, FEIN included,
 * which the next tracking lookup then searches by.
 */
class EwccvSearchHints extends Command
{
    protected $signature = 'ewccv:hints
        {vendor : Vendor id}
        {--name=* : Another name the business has used on a certificate}
        {--address=* : Another address, as "street, city, state zip" or just the street}
        {--fein= : The FEIN, for an exact search}
        {--check-as-of= : Also ask EWCCV for coverage as of this date (Y-m-d), search only}
        {--belongs-to-vendor-id=1 : The tenant asking}
        {--clear : Forget every hint first}
        {--show : Print the hints and change nothing}';

    protected $description = 'Record alternate names, addresses or the FEIN to search EWCCV by for a vendor, and queue a fresh lookup';

    public function handle(): int
    {
        $vendor = Vendor::withoutGlobalScopes()->find((int) $this->argument('vendor'));
        if (! $vendor) {
            $this->error('No such vendor.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line(json_encode(SearchHints::for($vendor), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('clear')) {
            SearchHints::clear($vendor);
            $this->info('Hints cleared.');
        }

        $addresses = array_map(fn (string $spec) => $this->parseAddress($spec), array_filter((array) $this->option('address')));
        SearchHints::save($vendor, [
            'names' => array_values(array_filter((array) $this->option('name'))),
            'addresses' => array_values(array_filter($addresses)),
            'fein' => $this->option('fein') ?: null,
        ]);
        $this->line('Hints now: '.json_encode(SearchHints::for($vendor), JSON_UNESCAPED_SLASHES));

        $belongsTo = (int) $this->option('belongs-to-vendor-id');
        $today = Carbon::today();
        $reset = 0;
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $belongsTo)
            ->whereDate('effective_date', '<=', $today)
            ->whereDate('expiration_date', '>=', $today)
            ->get();
        foreach ($docs as $doc) {
            $options = $doc->options ?? [];
            if (($options['ewccv']['status'] ?? null) === 'tracking' && empty($this->option('clear'))) {
                continue;
            }
            $options['ewccv'] = ['status' => 'pending', 'reason' => 'retry_with_hints', 'checked_at' => now()->toIso8601String()];
            $doc->options = $options;
            $doc->saveQuietly();
            $reset++;
        }
        $this->info("Queued a fresh tracking lookup for {$reset} active workers comp doc(s); it runs with the next browser session.");

        if ($asOf = $this->option('check-as-of')) {
            $date = Carbon::parse((string) $asOf)->toDateString();
            $check = EwccvCoverageCheck::firstOrNew(['vendor_id' => $vendor->id, 'belongs_to_vendor_id' => $belongsTo, 'coverage_date' => Carbon::parse($date)->startOfDay()]);
            $check->fill(['status' => EwccvCoverageCheck::STATUS_PENDING, 'reason' => null, 'requested_at' => now()])->save();
            $this->info("Queued a coverage check as of {$date} (search only) to capture the employer's EWCCV record.");
        }

        // A credential already on file may have searches left: use it now
        // rather than wait for the next hand-off.
        if (BridgedSession::canSearch()) {
            RunPendingEwccvLookups::dispatch();
            $this->info('A browser session is on file: the run starts now.');
        }

        return self::SUCCESS;
    }

    /** "3548 Grandmore Ave, Gurnee, IL 60031" → address / city / zip. */
    protected function parseAddress(string $spec): ?array
    {
        $parts = array_map('trim', explode(',', $spec));
        if ($parts === [] || $parts[0] === '') {
            return null;
        }
        $city = $parts[1] ?? null;
        $zip = null;
        if (isset($parts[2]) && preg_match('/(\d{5})(?:-\d{4})?\s*$/', $parts[2], $m)) {
            $zip = $m[1];
        } elseif ($city && preg_match('/^(.*?)\s+[A-Z]{2}\s+(\d{5})/', $city, $m)) {
            $city = $m[1];
            $zip = $m[2];
        }

        return ['address' => $parts[0], 'city' => $city ?: null, 'zip' => $zip];
    }
}
