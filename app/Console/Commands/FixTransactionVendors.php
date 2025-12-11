<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;

class FixTransactionVendors extends Command
{
    protected $signature = 'transactions:fix-vendors {--force : Run without confirmation}';

    protected $description = 'Fix incorrectly matched transaction vendors (27611 → Village of Arlington Heights, 27612 → null)';

    public function handle(): int
    {
        $fixes = [
            [
                'transaction_id' => 27611,
                'new_vendor_id' => 398,
                'reason' => 'Description contains "ARLINGTONHEIGHTSVILLAGE OF" - should be Village of Arlington Heights',
            ],
            [
                'transaction_id' => 27612,
                'new_vendor_id' => null,
                'reason' => 'Tyler Tech Service Fee - no matching vendor, requires manual match',
            ],
        ];

        $this->info('Transaction Vendor Fixes');
        $this->line('');

        // Show what will be changed
        $rows = [];
        foreach ($fixes as $fix) {
            $transaction = Transaction::withoutGlobalScopes()->find($fix['transaction_id']);

            if (! $transaction) {
                $this->warn("Transaction {$fix['transaction_id']} not found - skipping");
                continue;
            }

            $currentVendor = $transaction->vendor_id
                ? Vendor::withoutGlobalScopes()->find($transaction->vendor_id)?->business_name ?? 'Unknown'
                : 'None';

            $newVendor = $fix['new_vendor_id']
                ? Vendor::withoutGlobalScopes()->find($fix['new_vendor_id'])?->business_name ?? 'Unknown'
                : 'None (manual match required)';

            $rows[] = [
                $transaction->id,
                $transaction->plaid_merchant_description,
                $currentVendor,
                $newVendor,
                $fix['reason'],
            ];
        }

        $this->table(
            ['ID', 'Description', 'Current Vendor', 'New Vendor', 'Reason'],
            $rows
        );

        if (! $this->option('force') && ! $this->confirm('Apply these fixes?')) {
            $this->info('Cancelled.');

            return 0;
        }

        // Apply fixes
        $fixed = 0;
        foreach ($fixes as $fix) {
            $transaction = Transaction::withoutGlobalScopes()->find($fix['transaction_id']);

            if (! $transaction) {
                continue;
            }

            $oldVendorId = $transaction->vendor_id;
            $transaction->vendor_id = $fix['new_vendor_id'];
            $transaction->save();

            $this->info("✓ Transaction {$fix['transaction_id']}: vendor_id {$oldVendorId} → {$fix['new_vendor_id']}");
            $fixed++;
        }

        $this->line('');
        $this->info("Fixed {$fixed} transaction(s).");

        return 0;
    }
}
