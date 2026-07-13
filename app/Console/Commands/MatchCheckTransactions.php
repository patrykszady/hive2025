<?php

namespace App\Console\Commands;

use App\Http\Controllers\TransactionController;
use Illuminate\Console\Command;

class MatchCheckTransactions extends Command
{
    protected $signature = 'app:match-check-transactions';

    protected $description = 'Run the check↔transaction matcher (add_check_id_to_transactions) now instead of waiting for the 10-minute cron. Processes the most recent 500 unmatched checks.';

    public function handle(): int
    {
        $this->info('Running add_check_id_to_transactions…');

        app(TransactionController::class)->add_check_id_to_transactions();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
