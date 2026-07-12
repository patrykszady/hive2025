<?php

namespace App\Console\Commands;

use App\Http\Controllers\TransactionController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class FixExpenseTransactionMatching extends Command
{
    /**
     * One-shot production fix for the expense↔transaction mis-matching found
     * July 2026 (see FixBulkMatchAbsorbedTransactions / FixReturnedCheckFeeVendor):
     *
     *  - 27320 Groot:      two $647 charges piled onto one expense
     *  - 27295 Breck:      five $2.18 charges piled onto one expense
     *  - 26094 Forge:      absorbed the Dec 13 Microsoft charge
     *  - 26366 Microsoft:  absorbed a $12 returned-check bank fee
     */
    private const ABSORBED_EXPENSE_IDS = [27320, 27295, 26094, 26366];

    protected $signature = 'app:fix-expense-transaction-matching
        {--apply : Actually fix (default is a dry-run report)}
        {--skip-bulk-match : Do not run transaction_vendor_bulk_match afterwards}';

    protected $description = 'One-shot fix: re-vendor the returned-check fee, unlink absorbed duplicate transactions from the four known expenses, then run the bulk matcher so freed transactions get their own expenses. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $applyFlag = $apply ? ['--apply' => true] : [];

        // 1. Returned-check fee → Citi Checking vendor (also unlinks it from
        //    the Microsoft expense it was absorbed into).
        $this->info('1/3 Returned-check fee vendor…');
        Artisan::call('app:fix-returned-check-fee-vendor', $applyFlag, $this->getOutput());

        // 2. Unlink absorbed same-amount transactions (vendor-aware keep).
        $this->info('2/3 Absorbed duplicate transactions…');
        Artisan::call('app:fix-bulk-match-absorbed-transactions', $applyFlag + [
            '--expense' => self::ABSORBED_EXPENSE_IDS,
        ], $this->getOutput());

        // 3. Run the (fixed) bulk matcher now so freed transactions get their
        //    own expenses immediately instead of waiting for the cron.
        if ($apply && ! $this->option('skip-bulk-match')) {
            $this->info('3/3 Running transaction_vendor_bulk_match…');
            app(TransactionController::class)->transaction_vendor_bulk_match();
            $this->info('Done.');
        } else {
            $this->line($apply ? '3/3 Skipped bulk match.' : '3/3 Bulk match runs only with --apply.');
        }

        return self::SUCCESS;
    }
}
