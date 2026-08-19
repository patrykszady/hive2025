<?php

namespace App\Console\Commands;

use App\Models\VendorDoc;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Export subcontractors with active workers-comp policies, ready to load into
 * a COI-tracking platform (TrackMyVendor, …).
 *
 * These platforms verify the CERTIFICATE the sub provides, so they need the
 * sub's contact email — they chase the renewal themselves once the vendor
 * exists. Everything they need is already in our records; this is the bridge.
 *
 * Writes CSV so it can be bulk-imported, and prints a table for eyeballing.
 */
class ExportVendorsForComplianceTracking extends Command
{
    protected $signature = 'vendors:export-for-compliance
        {--belongs-to-vendor-id=1 : Owning vendor whose subcontractors to export}
        {--type=workers : Document type driving the list (workers|general)}
        {--path= : Where to write the CSV (default storage/app/compliance-import.csv)}
        {--include-expired : Include policies that have already lapsed}';

    protected $description = 'Export subcontractors and their insurance policies as CSV for a COI-tracking platform';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/compliance-import.csv');

        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', $this->option('type'))
            ->where('belongs_to_vendor_id', $this->option('belongs-to-vendor-id'))
            ->when(! $this->option('include-expired'), fn ($q) => $q->where('expiration_date', '>=', today()))
            ->with(['vendor', 'agent'])
            ->get()
            // One row per vendor: the policy expiring furthest out is the
            // current one, and duplicates would create duplicate parties.
            ->sortByDesc('expiration_date')
            ->unique('vendor_id')
            ->values();

        if ($docs->isEmpty()) {
            $this->warn('No matching policies found.');

            return self::SUCCESS;
        }

        $rows = $docs->map(function (VendorDoc $doc) {
            $vendor = $doc->vendor;

            return [
                'vendor_name' => $vendor?->business_name ?? "vendor {$doc->vendor_id}",
                'email' => $vendor?->business_email,
                'phone' => $vendor?->business_phone,
                'address' => trim(collect([$vendor?->address, $vendor?->city, $vendor?->state, $vendor?->zip_code])->filter()->implode(', ')),
                'policy_number' => $doc->number,
                'effective_date' => $doc->effective_date?->format('Y-m-d'),
                'expiration_date' => $doc->expiration_date?->format('Y-m-d'),
                'days_until_expiry' => $doc->expiration_date
                    ? (int) Carbon::today()->diffInDays($doc->expiration_date, false)
                    : null,
                'insurance_agent' => $doc->agent?->business_name ?? $doc->agent?->name,
                'agent_email' => $doc->agent?->email,
            ];
        });

        // CSV for bulk import.
        $handle = fopen($path, 'w');
        fputcsv($handle, array_keys($rows->first()));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $this->info("  Wrote {$rows->count()} subcontractor(s) → {$path}");
        $this->newLine();

        $this->table(
            ['Subcontractor', 'Email', 'Policy #', 'Expires', 'Days'],
            $rows->map(fn ($r) => [
                mb_strimwidth((string) $r['vendor_name'], 0, 30, '…'),
                mb_strimwidth((string) ($r['email'] ?: '— none —'), 0, 30, '…'),
                mb_strimwidth((string) $r['policy_number'], 0, 20, '…'),
                $r['expiration_date'],
                $r['days_until_expiry'],
            ])->toArray()
        );

        $missingEmail = $rows->filter(fn ($r) => blank($r['email']));

        if ($missingEmail->isNotEmpty()) {
            $this->newLine();
            $this->warn('  No email on file — these cannot be invited to upload a certificate:');
            foreach ($missingEmail as $r) {
                $this->line("    • {$r['vendor_name']}");
            }
        }

        $expiringSoon = $rows->filter(fn ($r) => $r['days_until_expiry'] !== null && $r['days_until_expiry'] <= 60);

        if ($expiringSoon->isNotEmpty()) {
            $this->newLine();
            $this->warn('  Expiring within 60 days — chase these first:');
            foreach ($expiringSoon as $r) {
                $this->line("    • {$r['vendor_name']} — {$r['expiration_date']} ({$r['days_until_expiry']} days)");
            }
        }

        return self::SUCCESS;
    }
}
