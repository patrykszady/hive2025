<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class FixTransferTransactionMerchants extends Command
{
    protected $signature = 'transactions:fix-transfer-merchants 
                            {--dry-run : Show what would be changed without making changes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Fix specific transactions with incorrect merchant/vendor assignments';

    public function handle(): int
    {
        // Specific transactions we identified that need fixing:
        // 27737 - ZELLE transaction incorrectly had "The Home Depot" merchant (already fixed locally)
        // 27611 - "OTHER DECREASE ARLINGTONHEIGHTS..." incorrectly matched to wrong vendor
        // 27612 - "OTHER DECREASE TYLER TECH..." should have vendor cleared
        
        $transactionIds = [27611, 27612];
        
        $this->info('Finding specific transactions to fix...');

        $transactions = Transaction::withoutGlobalScopes()
            ->whereIn('id', $transactionIds)
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('No transactions found.');
            return self::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions to fix:");
        $this->newLine();

        $tableData = $transactions->map(fn ($t) => [
            'ID' => $t->id,
            'Date' => $t->transaction_date?->format('Y-m-d'),
            'Amount' => '$' . number_format(abs($t->amount), 2),
            'Current Merchant' => $t->plaid_merchant_name,
            'Description' => substr($t->plaid_merchant_description ?? '', 0, 50) . '...',
            'Vendor ID' => $t->vendor_id,
        ])->toArray();

        $this->table(
            ['ID', 'Date', 'Amount', 'Current Merchant', 'Description', 'Vendor ID'],
            $tableData
        );

        // Define the corrections
        $corrections = [
            27611 => ['vendor_id' => 398], // Arlington Heights - correct vendor
            27612 => ['vendor_id' => null], // Tyler Tech fee - clear vendor
        ];

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - No changes made.');
            $this->info('Planned corrections:');
            foreach ($corrections as $id => $fix) {
                $this->line("  Transaction #{$id}: vendor_id -> " . ($fix['vendor_id'] ?? 'null'));
            }
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Apply these corrections?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($transactions as $transaction) {
            if (isset($corrections[$transaction->id])) {
                $fix = $corrections[$transaction->id];
                $transaction->vendor_id = $fix['vendor_id'];
                $transaction->save();
                $fixed++;
                $this->line("Fixed transaction #{$transaction->id}: vendor_id -> " . ($fix['vendor_id'] ?? 'null'));
            }
        }

        $this->newLine();
        $this->info("Successfully fixed {$fixed} transactions.");

        return self::SUCCESS;
    }
}
