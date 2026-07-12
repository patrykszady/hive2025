<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;

class FixReturnedCheckFeeVendor extends Command
{
    /**
     * Bank returned-check fees ("FEE-DEP CK RTN") historically match to the
     * Citi Checking vendor and get their own expense. One got fuzzy-matched
     * to Laravel Forge instead ($12 = the Forge subscription amount).
     */
    private const BANK_FEE_VENDOR_ID = 114; // Citi Checking

    protected $signature = 'app:fix-returned-check-fee-vendor
        {--apply : Actually re-assign the vendor (default is a dry-run report)}';

    protected $description = 'Re-assign "FEE-DEP CK RTN" (returned-check fee) transactions to the Citi Checking vendor, unlinking any expense that belongs to a different vendor. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $bankVendor = Vendor::withoutGlobalScopes()->find(self::BANK_FEE_VENDOR_ID);

        if (! $bankVendor || ! str_contains(strtolower((string) $bankVendor->business_name), 'citi')) {
            $this->error('Vendor ' . self::BANK_FEE_VENDOR_ID . ' is not the expected Citi Checking vendor — aborting.');

            return self::FAILURE;
        }

        $fees = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('plaid_merchant_description', 'LIKE', '%FEE-DEP CK RTN%')
            ->where(fn ($q) => $q
                ->whereNull('vendor_id')
                ->orWhere('vendor_id', '!=', self::BANK_FEE_VENDOR_ID))
            ->get();

        if ($fees->isEmpty()) {
            $this->info('All returned-check fee transactions already have the Citi Checking vendor.');

            return self::SUCCESS;
        }

        foreach ($fees as $fee) {
            $wrongExpense = null;
            if ($fee->expense_id) {
                $expense = Expense::withoutGlobalScopes()->find($fee->expense_id);
                if ($expense && (int) $expense->vendor_id !== self::BANK_FEE_VENDOR_ID) {
                    $wrongExpense = $expense;
                }
            }

            $this->line(sprintf(
                'Txn %d ($%s, %s): vendor %s → %d (Citi Checking)%s',
                $fee->id,
                $fee->amount,
                $fee->transaction_date->toDateString(),
                $fee->vendor_id ?? 'none',
                self::BANK_FEE_VENDOR_ID,
                $wrongExpense ? ", unlinking from vendor-{$wrongExpense->vendor_id} expense {$wrongExpense->id}" : '',
            ));

            if (! $apply) {
                continue;
            }

            if ($wrongExpense) {
                $fee->expense_id = null;
            }
            $fee->vendor_id = self::BANK_FEE_VENDOR_ID;
            $fee->save();
        }

        if ($apply) {
            $this->info("Re-assigned {$fees->count()} fee transactions.");
            $this->info('The transaction_vendor_bulk_match run will create their Citi Checking expenses.');
        } else {
            $this->warn('Dry-run only. Re-run with --apply to fix.');
        }

        return self::SUCCESS;
    }
}
