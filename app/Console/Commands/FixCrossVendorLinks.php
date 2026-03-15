<?php

namespace App\Console\Commands;

use App\Http\Controllers\TransactionController;
use App\Models\Transaction;
use Illuminate\Console\Command;

class FixCrossVendorLinks extends Command
{
    protected $signature = 'app:fix-cross-vendor-links';

    protected $description = 'Unlink transactions incorrectly matched to cross-vendor expenses, then re-match them';

    public function handle(): int
    {
        $badTransactionIds = [28254, 28225, 27868, 27838, 27751, 27488, 27386, 27262, 28321];

        $updated = Transaction::withoutEvents(function () use ($badTransactionIds) {
            return Transaction::whereIn('id', $badTransactionIds)
                ->whereNotNull('expense_id')
                ->update(['expense_id' => null]);
        });

        $this->info("Unlinked {$updated} bad transaction→expense links.");

        $this->info('Running add_expense_to_transactions to re-match...');
        app(TransactionController::class)->add_expense_to_transactions();

        $this->info('Done. Transactions have been re-matched to correct same-vendor expenses.');

        return self::SUCCESS;
    }
}
