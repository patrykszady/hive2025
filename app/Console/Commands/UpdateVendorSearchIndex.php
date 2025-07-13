<?php

namespace App\Console\Commands;

use App\Jobs\UpdateVendorSearchIndex as UpdateVendorSearchIndexJob;
use App\Models\Vendor;
use Illuminate\Console\Command;

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

        // Process vendors in chunks and dispatch jobs
        Vendor::chunk($chunkSize, function ($vendors) use ($progressBar, &$totalUpdated) {
            foreach ($vendors as $vendor) {
                UpdateVendorSearchIndexJob::dispatch($vendor->id);
                $totalUpdated++;
                $progressBar->advance();
            }
        });

        $progressBar->finish();

        $this->newLine();
        $this->info("Successfully dispatched {$totalUpdated} vendor search index update jobs.");

        return Command::SUCCESS;
    }
}
