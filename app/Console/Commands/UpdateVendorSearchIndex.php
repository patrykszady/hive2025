<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateVendorSearchIndex extends Command
{
    protected $signature = 'vendors:update-search-index {--chunk=100 : Number of vendors to process at once}';
    protected $description = 'Update vendor search index with current YTD expense sums';

    public function handle()
    {
        $this->info('Starting vendor search index update...');

        $chunkSize = (int) $this->option('chunk');
        $totalVendors = Vendor::count();

        $this->info("Processing {$totalVendors} vendors in chunks of {$chunkSize}...");

        $progressBar = $this->output->createProgressBar($totalVendors);
        $progressBar->start();

        $totalUpdated = 0;
        $startTime = microtime(true);

        // Process vendors in chunks to avoid memory issues
        Vendor::chunk($chunkSize, function ($vendors) use ($progressBar, &$totalUpdated) {
            // Pre-calculate expense sums with a single query
            $vendorIds = $vendors->pluck('id')->toArray();

            $expenseSums = DB::table('expenses')
                ->select('vendor_id', DB::raw('SUM(amount) as ytd_sum'))
                ->whereIn('vendor_id', $vendorIds)
                ->where('created_at', '>=', today()->subYear())
                ->groupBy('vendor_id')
                ->pluck('ytd_sum', 'vendor_id')
                ->toArray();

            // Update each vendor's search index
            foreach ($vendors as $vendor) {
                // Set the calculated sum as an attribute before indexing
                $vendor->setAttribute('ytd_expense_sum', $expenseSums[$vendor->id] ?? 0);
                $vendor->searchable();

                $totalUpdated++;
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        $this->newLine();
        $this->info("Successfully updated {$totalUpdated} vendors in search index.");
        $this->info("Completed in {$duration} seconds.");

        return Command::SUCCESS;
    }
}
