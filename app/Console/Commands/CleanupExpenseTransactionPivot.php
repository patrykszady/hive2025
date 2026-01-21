<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExpenseTransactionPivot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expense:cleanup-pivot {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove redundant expense_transaction pivot entries, keeping only multi-expense scenarios';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Analyzing expense_transaction pivot table...');

        // Count total entries
        $totalEntries = DB::table('expense_transaction')->count();
        $this->line("Total entries: {$totalEntries}");

        // Find transaction_ids that have multiple expenses (these are the ones we keep)
        $multiExpenseTransactionIds = DB::table('expense_transaction')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('transaction_id');

        $multiExpenseCount = DB::table('expense_transaction')
            ->whereIn('transaction_id', $multiExpenseTransactionIds)
            ->count();

        $this->info("Multi-expense entries to keep: {$multiExpenseCount}");

        // Find transaction_ids that only have ONE expense (redundant with legacy expense_id)
        $singleExpenseTransactionIds = DB::table('expense_transaction')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('transaction_id');

        $toDeleteCount = $singleExpenseTransactionIds->count();
        $this->warn("Redundant single-expense entries to delete: {$toDeleteCount}");

        if ($toDeleteCount === 0) {
            $this->info('No cleanup needed - table only contains multi-expense entries.');
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - no changes made. Run without --dry-run to delete.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("Delete {$toDeleteCount} redundant entries?")) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        // Delete in batches to avoid locking issues on large tables
        $deleted = 0;
        $batchSize = 1000;
        
        $this->output->progressStart($toDeleteCount);

        foreach ($singleExpenseTransactionIds->chunk($batchSize) as $chunk) {
            $batchDeleted = DB::table('expense_transaction')
                ->whereIn('transaction_id', $chunk)
                ->delete();
            $deleted += $batchDeleted;
            $this->output->progressAdvance($batchDeleted);
        }

        $this->output->progressFinish();

        $remaining = DB::table('expense_transaction')->count();
        $this->info("Deleted {$deleted} redundant entries.");
        $this->info("Remaining entries: {$remaining}");

        return Command::SUCCESS;
    }
}
