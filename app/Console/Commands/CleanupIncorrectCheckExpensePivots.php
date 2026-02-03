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
    protected $signature = 'checks:cleanup-pivots
        {--dry-run : Show what would be deleted without actually deleting}
        {--include-mismatched : Include pivots where expenses have a different check_id set directly}
        {--include-soft-deleted : Include pivots where the check or expense is soft-deleted}
        {--include-orphans : Include pivots missing a check or expense record}
        {--include-redundant-single : Include pivots where the check has only one expense and expense.check_id matches}
        {--include-transaction-single : Include pivots where the check has only one expense and a transaction exists for the check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove unused check_expense pivot records (mismatched, soft-deleted, or orphaned)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Finding unused check_expense pivot records...');
        $this->newLine();

        $includeSoftDeleted = (bool) $this->option('include-soft-deleted');
        $includeOrphans = (bool) $this->option('include-orphans');
        $includeMismatched = (bool) $this->option('include-mismatched');

        if (! $includeSoftDeleted && ! $includeOrphans && ! $includeMismatched) {
            $includeMismatched = true;
        }

        $pivotMap = [];

        if ($includeMismatched) {
            $mismatched = DB::table('check_expense as ce')
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

            foreach ($mismatched as $row) {
                $pivotMap[$row->pivot_id] = [
                    'pivot_id' => $row->pivot_id,
                    'pivot_check_id' => $row->pivot_check_id,
                    'expense_id' => $row->expense_id,
                    'expense_direct_check_id' => $row->expense_direct_check_id,
                    'amount' => $row->amount,
                    'date' => $row->date,
                    'pivot_created_at' => $row->pivot_created_at,
                    'reasons' => ['mismatched'],
                ];
            }
        }

        if ($includeSoftDeleted) {
            $softDeleted = DB::table('check_expense as ce')
                ->leftJoin('expenses as e', 'e.id', '=', 'ce.expense_id')
                ->leftJoin('checks as c', 'c.id', '=', 'ce.check_id')
                ->where(function ($query) {
                    $query->whereNotNull('e.deleted_at')
                        ->orWhereNotNull('c.deleted_at');
                })
                ->select([
                    'ce.id as pivot_id',
                    'ce.check_id as pivot_check_id',
                    'ce.expense_id',
                    'e.amount',
                    'e.date',
                    'ce.created_at as pivot_created_at',
                ])
                ->get();

            foreach ($softDeleted as $row) {
                if (! isset($pivotMap[$row->pivot_id])) {
                    $pivotMap[$row->pivot_id] = [
                        'pivot_id' => $row->pivot_id,
                        'pivot_check_id' => $row->pivot_check_id,
                        'expense_id' => $row->expense_id,
                        'expense_direct_check_id' => null,
                        'amount' => $row->amount,
                        'date' => $row->date,
                        'pivot_created_at' => $row->pivot_created_at,
                        'reasons' => ['soft-deleted'],
                    ];
                    continue;
                }

                $pivotMap[$row->pivot_id]['reasons'][] = 'soft-deleted';
            }
        }

        if ($includeOrphans) {
            $orphans = DB::table('check_expense as ce')
                ->leftJoin('expenses as e', 'e.id', '=', 'ce.expense_id')
                ->leftJoin('checks as c', 'c.id', '=', 'ce.check_id')
                ->where(function ($query) {
                    $query->whereNull('e.id')
                        ->orWhereNull('c.id');
                })
                ->select([
                    'ce.id as pivot_id',
                    'ce.check_id as pivot_check_id',
                    'ce.expense_id',
                    'e.amount',
                    'e.date',
                    'ce.created_at as pivot_created_at',
                ])
                ->get();

            foreach ($orphans as $row) {
                if (! isset($pivotMap[$row->pivot_id])) {
                    $pivotMap[$row->pivot_id] = [
                        'pivot_id' => $row->pivot_id,
                        'pivot_check_id' => $row->pivot_check_id,
                        'expense_id' => $row->expense_id,
                        'expense_direct_check_id' => null,
                        'amount' => $row->amount,
                        'date' => $row->date,
                        'pivot_created_at' => $row->pivot_created_at,
                        'reasons' => ['orphaned'],
                    ];
                    continue;
                }

                $pivotMap[$row->pivot_id]['reasons'][] = 'orphaned';
            }
        }

        if ((bool) $this->option('include-redundant-single')) {
            $redundantSingles = DB::table('check_expense as ce')
                ->join('expenses as e', 'e.id', '=', 'ce.expense_id')
                ->whereNotNull('e.check_id')
                ->whereColumn('e.check_id', '=', 'ce.check_id')
                ->whereIn('ce.check_id', function ($query) {
                    $query->from('check_expense')
                        ->select('check_id')
                        ->groupBy('check_id')
                        ->havingRaw('COUNT(*) = 1');
                })
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

            foreach ($redundantSingles as $row) {
                if (! isset($pivotMap[$row->pivot_id])) {
                    $pivotMap[$row->pivot_id] = [
                        'pivot_id' => $row->pivot_id,
                        'pivot_check_id' => $row->pivot_check_id,
                        'expense_id' => $row->expense_id,
                        'expense_direct_check_id' => $row->expense_direct_check_id,
                        'amount' => $row->amount,
                        'date' => $row->date,
                        'pivot_created_at' => $row->pivot_created_at,
                        'reasons' => ['redundant-single'],
                    ];
                    continue;
                }

                $pivotMap[$row->pivot_id]['reasons'][] = 'redundant-single';
            }
        }

        if ((bool) $this->option('include-transaction-single')) {
            $transactionSingles = DB::table('check_expense as ce')
                ->join('expenses as e', 'e.id', '=', 'ce.expense_id')
                ->join('transactions as t', function ($join) {
                    $join->on('t.check_id', '=', 'ce.check_id')
                        ->whereNull('t.deleted_at');
                })
                ->whereIn('ce.check_id', function ($query) {
                    $query->from('check_expense')
                        ->select('check_id')
                        ->groupBy('check_id')
                        ->havingRaw('COUNT(*) = 1');
                })
                ->select([
                    'ce.id as pivot_id',
                    'ce.check_id as pivot_check_id',
                    'ce.expense_id',
                    'e.check_id as expense_direct_check_id',
                    'e.amount',
                    'e.date',
                    'ce.created_at as pivot_created_at',
                ])
                ->distinct()
                ->get();

            foreach ($transactionSingles as $row) {
                if (! isset($pivotMap[$row->pivot_id])) {
                    $pivotMap[$row->pivot_id] = [
                        'pivot_id' => $row->pivot_id,
                        'pivot_check_id' => $row->pivot_check_id,
                        'expense_id' => $row->expense_id,
                        'expense_direct_check_id' => $row->expense_direct_check_id,
                        'amount' => $row->amount,
                        'date' => $row->date,
                        'pivot_created_at' => $row->pivot_created_at,
                        'reasons' => ['transaction-single'],
                    ];
                    continue;
                }

                $pivotMap[$row->pivot_id]['reasons'][] = 'transaction-single';
            }
        }

        $incorrectPivots = collect($pivotMap)->values();

        if ($incorrectPivots->isEmpty()) {
            $this->info('No incorrect pivot records found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$incorrectPivots->count()} unused pivot records:");
        $this->newLine();

        $this->table(
            ['Pivot ID', 'Expense ID', 'Pivot Check ID', 'Correct Check ID', 'Reasons', 'Amount', 'Date', 'Pivot Created'],
            $incorrectPivots->map(fn ($row) => [
                $row['pivot_id'],
                $row['expense_id'],
                $row['pivot_check_id'],
                $row['expense_direct_check_id'] ?? null,
                implode(', ', array_unique($row['reasons'])),
                $row['amount'] !== null ? '$' . number_format($row['amount'], 2) : null,
                $row['date'],
                $row['pivot_created_at'],
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
