<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use Illuminate\Console\Command;

class AmazonOrdersApiFullSync extends Command
{
    protected $signature = 'amazon:orders-api-full-sync
        {--vendor-id= : Limit full sync to a specific belongs_to_vendor_id}
        {--start-date= : Query orders and transaction feed starting on this UTC date (YYYY-MM-DD)}
        {--end-date= : Query orders and transaction feed ending on this UTC date (YYYY-MM-DD)}
        {--dry-run : Show what would run without executing sync}';

    protected $description = 'Force a full (30-day window) Amazon orders API sync and expense ingestion';

    public function handle(ReceiptController $receiptController): int
    {
        $vendorId = $this->option('vendor-id');
        $vendorId = $vendorId !== null && $vendorId !== '' ? (int) $vendorId : null;
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');

        if (($startDate && ! $endDate) || ($endDate && ! $startDate)) {
            $endDate = $endDate ?: $startDate;
            $startDate = $startDate ?: $endDate;
        }

        if ($this->option('dry-run')) {
            if ($vendorId !== null && $startDate !== null && $endDate !== null) {
                $this->info("Dry run: would run Amazon full sync for belongs_to_vendor_id {$vendorId} from {$startDate} to {$endDate}.");
            } elseif ($vendorId !== null) {
                $this->info("Dry run: would run Amazon full sync for belongs_to_vendor_id {$vendorId}.");
            } elseif ($startDate !== null && $endDate !== null) {
                $this->info("Dry run: would run Amazon full sync for all connected Amazon receipt accounts from {$startDate} to {$endDate}.");
            } else {
                $this->info('Dry run: would run Amazon full sync for all connected Amazon receipt accounts.');
            }

            return self::SUCCESS;
        }

        if ($vendorId !== null && $startDate !== null && $endDate !== null) {
            $this->info("Running Amazon full sync for belongs_to_vendor_id {$vendorId} from {$startDate} to {$endDate}...");
        } elseif ($vendorId !== null) {
            $this->info("Running Amazon full sync for belongs_to_vendor_id {$vendorId}...");
        } elseif ($startDate !== null && $endDate !== null) {
            $this->info("Running Amazon full sync for all connected Amazon receipt accounts from {$startDate} to {$endDate}...");
        } else {
            $this->info('Running Amazon full sync for all connected Amazon receipt accounts...');
        }

        $receiptController->amazon_orders_api(
            forceFullSync: true,
            onlyBelongsToVendorId: $vendorId,
            startDateOverride: $startDate,
            endDateOverride: $endDate,
        );

        $this->info('Amazon full sync completed.');

        return self::SUCCESS;
    }
}
