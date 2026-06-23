<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use Illuminate\Console\Command;

class AmazonOrdersApiFullSync extends Command
{
    protected $signature = 'amazon:orders-api-full-sync
        {--vendor-id= : Limit full sync to a specific belongs_to_vendor_id}
        {--dry-run : Show what would run without executing sync}';

    protected $description = 'Force a full (30-day window) Amazon orders API sync and expense ingestion';

    public function handle(ReceiptController $receiptController): int
    {
        $vendorId = $this->option('vendor-id');
        $vendorId = $vendorId !== null && $vendorId !== '' ? (int) $vendorId : null;

        if ($this->option('dry-run')) {
            if ($vendorId !== null) {
                $this->info("Dry run: would run Amazon full sync for belongs_to_vendor_id {$vendorId}.");
            } else {
                $this->info('Dry run: would run Amazon full sync for all connected Amazon receipt accounts.');
            }

            return self::SUCCESS;
        }

        if ($vendorId !== null) {
            $this->info("Running Amazon full sync for belongs_to_vendor_id {$vendorId}...");
        } else {
            $this->info('Running Amazon full sync for all connected Amazon receipt accounts...');
        }

        $receiptController->amazon_orders_api(forceFullSync: true, onlyBelongsToVendorId: $vendorId);

        $this->info('Amazon full sync completed.');

        return self::SUCCESS;
    }
}
