<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Delete checks entered twice, the way the check page's Delete does: the
 * check is soft-deleted and CheckObserver unlinks its bank payments and
 * timesheets, unlinks expenses it merely settled and deletes the ones it
 * created (all restorable with the check). Cash check id 3563 ($160,
 * 06/07/2025) duplicated id 3564, entered 86 seconds apart on 09/26/2025.
 */
class DeleteChecks extends Command
{
    protected $signature = 'checks:delete
        {ids* : The checks to delete}
        {--dry-run : Show what would go with each check, change nothing}';

    protected $description = 'Delete duplicate checks as the check page does (restorable)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $ids = array_values(array_unique(array_map('intval', (array) $this->argument('ids'))));

        foreach ($ids as $id) {
            $check = Check::withoutGlobalScopes()->whereNull('deleted_at')->find($id);
            if (! $check) {
                $this->warn("  {$id}: no such check, or already deleted");

                continue;
            }

            $expenses = Expense::withoutGlobalScopes()->where('check_id', $id)->get();
            $settled = $expenses->filter(fn (Expense $e) => $e->paid_by !== null || $check->isSettlementDeduction($e));
            $payments = Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('check_id', $id)->count();
            $timesheets = Timesheet::withoutGlobalScopes()->where('check_id', $id)->count();

            $this->line(sprintf('  check %d  %s  %s  %s: %d expense(s) deleted, %d unlinked, %d bank payment(s) and %d timesheet(s) unlinked%s',
                $id, $check->check_type, $check->date?->format('m/d/Y'), money($check->amount),
                $expenses->count() - $settled->count(), $settled->count(), $payments, $timesheets, $dryRun ? '  (dry run)' : ''));

            if (! $dryRun) {
                $check->delete();
            }
            $deleted++;
        }

        $this->info(sprintf('%s %d of %d.', $dryRun ? 'Would delete' : 'Deleted', $deleted, count($ids)));

        return self::SUCCESS;
    }
}
