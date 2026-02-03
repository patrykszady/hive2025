<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildCheckExpensePivots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checks:rebuild-pivots
        {--dry-run : Show what would be inserted without inserting}
        {--include-soft-deleted : Include soft-deleted checks or expenses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild check_expense pivots for checks with multiple expenses';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $includeSoftDeleted = (bool) $this->option('include-soft-deleted');

        $multiExpenseChecks = DB::table('expenses')
            ->select('check_id')
            ->whereNotNull('check_id')
            ->when(! $includeSoftDeleted, fn ($query) => $query->whereNull('deleted_at'))
            ->groupBy('check_id')
            ->havingRaw('COUNT(*) > 1');

        $baseQuery = DB::table('expenses as e')
            ->joinSub($multiExpenseChecks, 'm', 'm.check_id', '=', 'e.check_id')
            ->join('checks as c', 'c.id', '=', 'e.check_id')
            ->leftJoin('check_expense as ce', function ($join) {
                $join->on('ce.check_id', '=', 'e.check_id')
                    ->on('ce.expense_id', '=', 'e.id');
            })
            ->whereNull('ce.id')
            ->when(! $includeSoftDeleted, function ($query) {
                $query->whereNull('e.deleted_at')
                    ->whereNull('c.deleted_at');
            });

        $toInsert = (clone $baseQuery)->count();

        if ($toInsert === 0) {
            $this->info('No pivot records to insert.');
            return self::SUCCESS;
        }

        $this->info("Found {$toInsert} pivot records to insert.");

        if ($this->option('dry-run')) {
            $this->newLine();
            $preview = (clone $baseQuery)
                ->select(['e.check_id', 'e.id as expense_id', 'e.amount', 'e.date'])
                ->orderBy('e.check_id')
                ->orderBy('e.id')
                ->limit(25)
                ->get();

            $this->table(['Check ID', 'Expense ID', 'Amount', 'Date'], $preview->toArray());
            $this->newLine();
            $this->info('Dry run mode - no records were inserted.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Do you want to insert these pivot records?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $inserted = DB::table('check_expense')->insertUsing(
            ['check_id', 'expense_id', 'created_at', 'updated_at'],
            $baseQuery->select([
                'e.check_id',
                'e.id as expense_id',
                DB::raw('NOW()'),
                DB::raw('NOW()'),
            ])
        );

        $this->info("Inserted {$inserted} pivot records.");

        return self::SUCCESS;
    }
}
