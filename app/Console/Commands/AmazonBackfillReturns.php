<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use Illuminate\Console\Command;

class AmazonBackfillReturns extends Command
{
    protected $signature = 'amazon:backfill-returns
        {--vendor-id= : Limit backfill to a specific belongs_to_vendor_id}
        {--start-date= : Backfill window start date (YYYY-MM-DD)}
        {--end-date= : Backfill window end date (YYYY-MM-DD)}
        {--dry-run : Show what would run without executing}';

    protected $description = 'Backfill Amazon return expenses and link unmatched transactions for a date window';

    public function handle(ReceiptController $receiptController, TransactionController $transactionController): int
    {
        $vendorId = $this->option('vendor-id');
        $vendorId = $vendorId !== null && $vendorId !== '' ? (int) $vendorId : null;
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');

        if (($startDate && ! $endDate) || ($endDate && ! $startDate)) {
            $endDate = $endDate ?: $startDate;
            $startDate = $startDate ?: $endDate;
        }

        if ($startDate === null && $endDate === null) {
            $this->error('Please provide --start-date and --end-date (or one date and it will be used for both).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            if ($vendorId !== null) {
                $this->info("Dry run: would backfill Amazon returns for belongs_to_vendor_id {$vendorId} from {$startDate} to {$endDate}, then run add_expense_to_transactions.");
            } else {
                $this->info("Dry run: would backfill Amazon returns for all connected Amazon accounts from {$startDate} to {$endDate}, then run add_expense_to_transactions.");
            }

            return self::SUCCESS;
        }

        if ($vendorId !== null) {
            $this->info("Running Amazon return backfill for belongs_to_vendor_id {$vendorId} from {$startDate} to {$endDate}...");
        } else {
            $this->info("Running Amazon return backfill for all connected Amazon accounts from {$startDate} to {$endDate}...");
        }

        $receiptController->amazon_orders_api(
            forceFullSync: true,
            onlyBelongsToVendorId: $vendorId,
            startDateOverride: $startDate,
            endDateOverride: $endDate,
        );

        $this->info('Amazon sync completed. Running transaction-to-expense linking...');

        $transactionController->add_expense_to_transactions();

        $this->info('Amazon return backfill completed.');

        return self::SUCCESS;
    }
}
