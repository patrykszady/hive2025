<?php

namespace App\Observers;

use App\Models\Check;

class CheckObserver
{
    /**
     * Handle the Check "created" event.
     */
    public function created(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "updated" event.
     */
    public function updated(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "deleted" event.
     */
    public function deleted(Check $check): void
    {
        // Get IDs before bulk updates (bulk updates bypass Scout indexing)
        $expenseIds = $check->expenses()->pluck('id')->toArray();
        $transactionIds = $check->transactions()->pluck('id')->toArray();

        // Expenses that pre-existed the payment and were merely SETTLED by it
        // (employee-paid expenses and reimbursements deducted on this check)
        // must survive the check: unlink them so they become payable /
        // deductible again — same treatment as timesheets. Expenses CREATED
        // by the payment (vendor project expenses, an expense paid by its own
        // merchant check) die with the check.
        $settledIds = $check->expenses()->get()
            ->filter(fn ($e) => $e->paid_by !== null || $check->isSettlementDeduction($e))
            ->pluck('id');
        if ($settledIds->isNotEmpty()) {
            $check->expenses()->whereIn('id', $settledIds)->update(['check_id' => null]);
        }

        // Soft-delete remaining expenses tied to this check so they stop counting toward totals
        $check->expenses()->delete();
        $check->timesheets()->update(['check_id' => null]);
        $check->transactions()->update(['check_id' => null]);

        // Detach scanned check images (soft delete never fires the FK's
        // nullOnDelete) and requeue them for matching
        \App\Models\CheckImage::where('check_id', $check->id)->update([
            'check_id' => null,
            'match_status' => \App\Models\CheckImage::STATUS_PENDING,
        ]);

        // Re-index expenses in Meilisearch after soft-delete
        if (! empty($expenseIds)) {
            \App\Models\Expense::withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', $expenseIds)
                ->searchable();
        }

        // Re-index transactions in Meilisearch after bulk update
        if (! empty($transactionIds)) {
            \App\Models\Transaction::withoutGlobalScopes()
                ->whereIn('id', $transactionIds)
                ->searchable();
        }
    }

    /**
     * Handle the Check "restored" event.
     */
    public function restored(Check $check): void
    {
        // Restore expenses that were soft-deleted when the check was deleted
        $restoredCount = $check->expenses()->onlyTrashed()->restore();

        // Re-index restored expenses in Meilisearch
        if ($restoredCount > 0) {
            $check->expenses()->searchable();
        }

        // Settled expenses were UNLINKED on delete (not trashed) and stay
        // unlinked — recalculate so the restored check's amount reflects only
        // what is actually still attached.
        $check->recalculateAmount();
    }

    /**
     * Handle the Check "force deleted" event.
     */
    public function forceDeleted(Check $check): void
    {
        //
    }
}
