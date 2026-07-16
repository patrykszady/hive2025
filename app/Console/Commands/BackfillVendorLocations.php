<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;

/**
 * Fill empty vendor city/state/zip from the Plaid locations of their linked
 * transactions, so generic same-named vendors ("SMOKE N VAPE", "Chinese Food")
 * become location-specific and the location gate in
 * TransactionController::add_vendor_to_transactions can tell them apart.
 *
 *   php artisan app:backfill-vendor-locations                 (review table)
 *   php artisan app:backfill-vendor-locations --apply
 *   php artisan app:backfill-vendor-locations --vendor=723 --apply
 *
 * Conservative on purpose: only vendors with NO city on file are candidates,
 * and only when every located transaction agrees on a single city (chains
 * whose history spans multiple stores are skipped). Never overwrites.
 *
 * This backfills SOFT location only (city/state/zip — display and positive
 * disambiguation). The hard matching veto requires a pinned location (street
 * address + zip, see Vendor::hasPinnedLocation), which stays a deliberate
 * human action so chains never start bouncing to manual review.
 */
class BackfillVendorLocations extends Command
{
    protected $signature = 'app:backfill-vendor-locations
                            {--vendor=* : Limit to specific vendor id(s)}
                            {--min-agree=3 : Minimum located transactions required}
                            {--apply : Persist the proposed locations}';

    protected $description = 'Fill empty vendor city/state/zip from linked transactions\' Plaid locations.';

    public function handle(): int
    {
        $minAgree = max(1, (int) $this->option('min-agree'));

        $vendors = Vendor::withoutGlobalScopes()
            ->where(function ($query) {
                $query->whereNull('city')->orWhere('city', '');
            })
            ->when($this->option('vendor'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->whereHas('transactions', fn ($q) => $q->withoutGlobalScopes())
            ->get();

        $rows = [];
        $applied = 0;

        foreach ($vendors as $vendor) {
            $locations = Transaction::withoutGlobalScopes()
                ->where('vendor_id', $vendor->id)
                ->whereNull('deleted_at')
                ->withOnly([])
                ->select(['id', 'details'])
                ->get()
                ->map(fn (Transaction $t) => $t->plaidLocation())
                ->filter(fn (?array $loc) => $loc && $loc['city'] !== '');

            if ($locations->count() < $minAgree) {
                continue;
            }

            // Canonicalize bank-truncated city names: banks emit leading AND
            // trailing fragments ("Mount" and "Prospect" both mean Mount
            // Prospect). A fragment maps to a longer city only when exactly
            // ONE longer city in the set extends it either way — "Prospect"
            // alongside both "Mount Prospect" and "Prospect Heights" stays
            // its own group, which then trips the multi-city chain skip.
            $names = $locations->pluck('city')->unique(fn ($c) => mb_strtolower($c))->values();
            $canonical = [];
            foreach ($names as $name) {
                $lower = mb_strtolower($name);
                $longer = $names
                    ->filter(function ($n) use ($lower) {
                        $candidate = mb_strtolower($n);

                        return str_starts_with($candidate, $lower.' ')
                            || str_ends_with($candidate, ' '.$lower);
                    })
                    ->unique(fn ($n) => mb_strtolower($n));
                $canonical[$lower] = $longer->count() === 1 ? $longer->first() : $name;
            }

            $byCity = $locations
                ->groupBy(fn (array $loc) => mb_strtolower($canonical[mb_strtolower($loc['city'])]))
                ->sortByDesc(fn ($group) => $group->count());

            $dominant = $byCity->first();
            $share = $dominant->count() / $locations->count();
            $city = $canonical[mb_strtolower($dominant->first()['city'])];

            if ($byCity->count() > 1) {
                $rows[] = [
                    $vendor->id,
                    $vendor->business_name,
                    $locations->count(),
                    $city.', '.$dominant->first()['region'],
                    round($share * 100).'%',
                    'SKIP: multiple cities — looks like a chain',
                ];

                continue;
            }

            $regions = $dominant->pluck('region')->filter()->map(fn ($r) => strtoupper($r))->unique();
            if ($regions->count() > 1) {
                $rows[] = [
                    $vendor->id,
                    $vendor->business_name,
                    $locations->count(),
                    $city,
                    '100%',
                    'SKIP: conflicting states',
                ];

                continue;
            }

            $region = $regions->first() ?? '';
            $zips = $dominant->pluck('postal_code')->filter(fn ($z) => strlen($z) === 5)->unique();
            $zip = $zips->count() === 1 ? $zips->first() : null;

            $rows[] = [
                $vendor->id,
                $vendor->business_name,
                $locations->count(),
                $city.', '.$region.($zip ? ' '.$zip : ''),
                '100%',
                $this->option('apply') ? 'APPLIED' : 'would apply',
            ];

            if ($this->option('apply')) {
                // Model save so Scout re-indexes (vendor city is searchable).
                // Filter nulls: never overwrite an existing state/zip with
                // nothing just because the bank data lacked it.
                $vendor->update(array_filter([
                    'city' => $city,
                    'state' => strlen($region) === 2 ? $region : null,
                    'zip_code' => $zip,
                ], fn ($value) => $value !== null));
                $applied++;
            }
        }

        if ($rows === []) {
            $this->info('No vendors with enough located transactions found.');

            return self::SUCCESS;
        }

        $this->table(['Vendor', 'Name', 'Located Txns', 'Dominant Location', 'Share', 'Action'], $rows);

        $this->info($this->option('apply')
            ? "Applied {$applied} vendor location(s)."
            : 'Dry run — pass --apply to persist.');

        return self::SUCCESS;
    }
}
