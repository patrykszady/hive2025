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
        $corrections = [
            27611 => [
                'vendor_id' => 398,
                'reason' => 'Arlington Heights village payment - correct vendor',
            ],
            27612 => [
                'vendor_id' => null,
                'reason' => 'Tyler Tech service fee - clear incorrect vendor',
            ],
            27737 => [
                'plaid_merchant_name' => null,
                'vendor_id' => null,
                'reason' => 'ZELLE payment incorrectly had Home Depot merchant name',
            ],
            27762 => [
                'vendor_id' => 8,
                'expense_id' => 26007,
                'check_number' => null,
                'reason' => 'Home Depot - link to correct expense',
            ],
        ];
        
        $transactionIds = array_keys($corrections);
        
        $this->info('Finding specific transactions to fix...');

        $transactions = Transaction::withoutGlobalScopes()
            ->whereIn('id', $transactionIds)
            ->get()
            ->keyBy('id');

        if ($transactions->isEmpty()) {
            $this->info('No transactions found.');
            return self::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions:");
        $this->newLine();

        $tableData = $transactions->map(fn ($t) => [
            'ID' => $t->id,
            'Date' => $t->transaction_date?->format('Y-m-d'),
            'Amount' => '$' . number_format(abs($t->amount), 2),
            'Merchant' => $t->plaid_merchant_name,
            'Description' => substr($t->plaid_merchant_description ?? '', 0, 40) . '...',
            'Vendor' => $t->vendor_id,
            'Expense' => $t->expense_id,
        ])->toArray();

        $this->table(
            ['ID', 'Date', 'Amount', 'Merchant', 'Description', 'Vendor', 'Expense'],
            $tableData
        );

        $this->newLine();
        $this->info('Planned corrections:');
        foreach ($corrections as $id => $fix) {
            $changes = [];
            if (array_key_exists('plaid_merchant_name', $fix)) {
                $changes[] = 'merchant_name -> ' . ($fix['plaid_merchant_name'] ?? 'null');
            }
            if (array_key_exists('vendor_id', $fix)) {
                $changes[] = 'vendor_id -> ' . ($fix['vendor_id'] ?? 'null');
            }
            if (array_key_exists('expense_id', $fix)) {
                $changes[] = 'expense_id -> ' . ($fix['expense_id'] ?? 'null');
            }
            if (array_key_exists('check_number', $fix)) {
                $changes[] = 'check_number -> ' . ($fix['check_number'] ?? 'null');
            }
            $this->line("  #{$id}: " . implode(', ', $changes) . " ({$fix['reason']})");
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('DRY RUN - No changes made.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Apply these corrections?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($corrections as $id => $fix) {
            $transaction = $transactions->get($id);
            if (!$transaction) {
                $this->warn("Transaction #{$id} not found, skipping.");
                continue;
            }

            if (array_key_exists('plaid_merchant_name', $fix)) {
                $transaction->plaid_merchant_name = $fix['plaid_merchant_name'];
            }
            if (array_key_exists('vendor_id', $fix)) {
                $transaction->vendor_id = $fix['vendor_id'];
            }
            if (array_key_exists('expense_id', $fix)) {
                $transaction->expense_id = $fix['expense_id'];
            }
            if (array_key_exists('check_number', $fix)) {
                $transaction->check_number = $fix['check_number'];
            }
            
            $transaction->save();
            $fixed++;
            $this->line("✓ Fixed transaction #{$id}");
        }

        $this->newLine();
        $this->info("Successfully fixed {$fixed} transactions.");

        return self::SUCCESS;
    }
}
