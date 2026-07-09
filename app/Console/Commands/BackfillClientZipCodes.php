<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\GeoapifyService;
use Illuminate\Console\Command;

class BackfillClientZipCodes extends Command
{
    protected $signature = 'clients:backfill-zip-codes
        {--dry-run : Show what would be filled without saving}';

    protected $description = 'Geocode clients that have an address but no ZIP code (e.g. lead imports from the marketing site) and fill in the missing ZIP.';

    public function handle(GeoapifyService $geo): int
    {
        $clients = Client::query()
            ->where(fn ($q) => $q->whereNull('zip_code')->orWhere('zip_code', ''))
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->get();

        $this->info("Clients missing a ZIP code: {$clients->count()}");

        $filled = 0;
        foreach ($clients as $client) {
            // A bare street with no city/state anchors nowhere — Geoapify will
            // happily match "123 Main St" to another state. Skip those.
            if (empty($client->city) && empty($client->state)) {
                $this->warn("  client {$client->id}: skipped — no city/state to anchor \"{$client->address}\"");

                continue;
            }

            $lookupAddress = collect([
                $client->address,
                $client->city,
                $client->state,
            ])->filter()->implode(', ');

            $zip = $geo->lookupZipCode($lookupAddress);

            if (! $zip) {
                $this->warn("  client {$client->id}: no ZIP found for \"{$lookupAddress}\"");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  client {$client->id}: \"{$lookupAddress}\" → {$zip} (dry run)");
            } else {
                $client->update(['zip_code' => $zip]);
                $this->line("  client {$client->id}: \"{$lookupAddress}\" → {$zip}");
            }

            $filled++;
        }

        $this->info(($this->option('dry-run') ? 'Would fill' : 'Filled') . ": {$filled} ZIP code(s).");

        return self::SUCCESS;
    }
}
