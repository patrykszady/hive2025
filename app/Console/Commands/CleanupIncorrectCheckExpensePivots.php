<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupIncorrectCheckExpensePivots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checks:cleanup-pivots {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove incorrect check_expense pivot records where expenses already have a different check_id set directly';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Finding incorrect check_expense pivot records...');
        $this->newLine();

        // Find pivot records where the expense already has a different check_id set directly
        $incorrectPivots = DB::table('check_expense as ce')
            ->join('expenses as e', 'e.id', '=', 'ce.expense_id')
            ->whereNotNull('e.check_id')
            ->whereColumn('e.check_id', '!=', 'ce.check_id')
            ->select([
                'ce.id as pivot_id',
                'ce.check_id as pivot_check_id',
                'ce.expense_id',
                'e.check_id as expense_direct_check_id',
                'e.amount',
                'e.date',
                'ce.created_at as pivot_created_at',
            ])
            ->get();

        if ($incorrectPivots->isEmpty()) {
            $this->info('No incorrect pivot records found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$incorrectPivots->count()} incorrect pivot records:");
        $this->newLine();

        $this->table(
            ['Pivot ID', 'Expense ID', 'Pivot Check ID', 'Correct Check ID', 'Amount', 'Date', 'Pivot Created'],
            $incorrectPivots->map(fn ($row) => [
                $row->pivot_id,
                $row->expense_id,
                $row->pivot_check_id,
                $row->expense_direct_check_id,
                '$' . number_format($row->amount, 2),
                $row->date,
                $row->pivot_created_at,
            ])
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run mode - no records were deleted.');
            $this->info('Run without --dry-run to delete these records.');
            return self::SUCCESS;
        }

        $this->newLine();
        if (!$this->confirm('Do you want to delete these incorrect pivot records?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $pivotIds = $incorrectPivots->pluck('pivot_id')->toArray();
        $deleted = DB::table('check_expense')->whereIn('id', $pivotIds)->delete();

        $this->newLine();
        $this->info("Successfully deleted {$deleted} incorrect pivot records.");

        return self::SUCCESS;
    }
}
