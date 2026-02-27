<?php

namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;

class CleanupOrphanedCheckExpenses extends Command
{
    protected $signature = 'expenses:cleanup-orphaned-check-expenses
        {--execute : Apply soft-deletes (default is dry-run)}
        {--vendor-id= : Only process a single vendor_id}
        {--project-id= : Only process a single project_id}
        {--window-days=30 : Max day gap allowed between orphan and matched paid expense}
    ';

    protected $description = 'Find and optionally soft-delete likely orphaned expenses left behind when checks were deleted before the observer fix.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $vendorId = $this->option('vendor-id');
        $projectId = $this->option('project-id');
        $windowDays = max(1, (int) $this->option('window-days'));

        $this->info($execute ? 'Executing orphan expense cleanup...' : 'Dry-run (no writes).');
        $this->line('Matching window (days): ' . $windowDays);

        $query = Expense::query()
            ->withoutGlobalScopes()
            ->whereNull('expenses.deleted_at')
            ->whereNull('expenses.check_id')
            ->whereNotNull('expenses.project_id')
            ->whereNotNull('expenses.vendor_id')
            ->when($vendorId !== null && $vendorId !== '', function ($builder) use ($vendorId) {
                $builder->where('expenses.vendor_id', (int) $vendorId);
            })
            ->when($projectId !== null && $projectId !== '', function ($builder) use ($projectId) {
                $builder->where('expenses.project_id', (int) $projectId);
            })
            ->whereExists(function ($builder) use ($windowDays) {
                $builder->selectRaw('1')
                    ->from('expenses as matched')
                    ->join('checks as checks', 'checks.id', '=', 'matched.check_id')
                    ->whereNull('matched.deleted_at')
                    ->whereNull('checks.deleted_at')
                    ->whereNotNull('matched.check_id')
                    ->whereColumn('matched.project_id', 'expenses.project_id')
                    ->whereColumn('matched.vendor_id', 'expenses.vendor_id')
                    ->whereColumn('matched.amount', 'expenses.amount')
                    ->whereRaw('COALESCE(matched.belongs_to_vendor_id, 0) = COALESCE(expenses.belongs_to_vendor_id, 0)')
                    ->whereRaw('ABS(TIMESTAMPDIFF(DAY, expenses.created_at, matched.created_at)) <= ?', [$windowDays]);
            })
            ->orderBy('expenses.vendor_id')
            ->orderBy('expenses.project_id')
            ->orderBy('expenses.created_at');

        $candidates = $query->get([
            'expenses.id',
            'expenses.vendor_id',
            'expenses.project_id',
            'expenses.belongs_to_vendor_id',
            'expenses.amount',
            'expenses.created_at',
        ]);

        if ($candidates->isEmpty()) {
            $this->info('No orphaned expense candidates found.');

            return self::SUCCESS;
        }

        $this->info('Candidate rows found: ' . number_format($candidates->count()));

        $rows = [];
        $deletedIds = [];

        foreach ($candidates as $expense) {
            $matched = Expense::query()
                ->withoutGlobalScopes()
                ->from('expenses as matched')
                ->join('checks as checks', 'checks.id', '=', 'matched.check_id')
                ->whereNull('matched.deleted_at')
                ->whereNull('checks.deleted_at')
                ->whereNotNull('matched.check_id')
                ->where('matched.id', '!=', $expense->id)
                ->where('matched.project_id', $expense->project_id)
                ->where('matched.vendor_id', $expense->vendor_id)
                ->where('matched.amount', $expense->amount)
                ->whereRaw('COALESCE(matched.belongs_to_vendor_id, 0) = COALESCE(?, 0)', [$expense->belongs_to_vendor_id])
                ->whereRaw('ABS(TIMESTAMPDIFF(DAY, ?, matched.created_at)) <= ?', [
                    $expense->created_at,
                    $windowDays,
                ])
                ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, ?, matched.created_at))', [$expense->created_at])
                ->first([
                    'matched.id as matched_expense_id',
                    'matched.check_id as matched_check_id',
                    'matched.created_at as matched_created_at',
                ]);

            if (! $matched) {
                continue;
            }

            $rows[] = [
                'expense_id' => $expense->id,
                'vendor_id' => $expense->vendor_id,
                'project_id' => $expense->project_id,
                'amount' => '$' . number_format((float) $expense->amount, 2),
                'created_at' => optional($expense->created_at)->format('Y-m-d H:i'),
                'matched_expense_id' => $matched->matched_expense_id,
                'matched_check_id' => $matched->matched_check_id,
            ];

            if ($execute) {
                Expense::query()
                    ->withoutGlobalScopes()
                    ->whereKey($expense->id)
                    ->delete();

                $deletedIds[] = $expense->id;
            }
        }

        if (empty($rows)) {
            $this->info('No orphaned expense candidates survived final matching.');

            return self::SUCCESS;
        }

        $this->table(
            ['Expense ID', 'Vendor', 'Project', 'Amount', 'Created', 'Matched Expense', 'Matched Check'],
            $rows
        );

        if (! $execute) {
            $this->newLine();
            $this->warn('Dry run complete. Re-run with --execute to soft-delete listed expenses.');

            return self::SUCCESS;
        }

        if (! empty($deletedIds)) {
            Expense::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', $deletedIds)
                ->searchable();
        }

        $this->newLine();
        $this->info('Execution complete. Soft-deleted expenses: ' . number_format(count($deletedIds)));

        return self::SUCCESS;
    }
}
