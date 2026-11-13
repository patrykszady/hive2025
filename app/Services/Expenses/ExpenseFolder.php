<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Support\Facades\DB;

/**
 * Folds one expense into another that records the same money: the one way
 * two expenses become one (PaidInvoiceMerger's invoice into its payment,
 * ReceiptChargePairer's receipt into its bank charge).
 *
 * Every receipt (row and stored file — the file keeps its name) moves onto
 * the surviving expense, which also takes the folded expense's project and
 * invoice number when it has none of its own. The folded expense is then
 * soft-deleted the way ExpenseForm::delete() does it, and the survivor is
 * re-indexed. Nothing the survivor already holds is overwritten.
 */
class ExpenseFolder
{
    public function fold(Expense $folded, Expense $survivor): void
    {
        DB::transaction(function () use ($folded, $survivor): void {
            ExpenseReceipts::withoutGlobalScopes()
                ->where('expense_id', $folded->id)
                ->get()
                ->each(function (ExpenseReceipts $receipt) use ($survivor): void {
                    $receipt->expense_id = $survivor->id;
                    $receipt->save();
                });

            $hasAllocation = $survivor->project_id || $survivor->distribution_id || $survivor->splits()->exists();

            if (! $hasAllocation && $folded->project_id) {
                $survivor->project_id = $folded->project_id;
            }

            if (blank($survivor->invoice) && filled($folded->invoice)) {
                $survivor->invoice = $folded->invoice;
            }

            $survivor->save();

            foreach ($folded->associated as $associated) {
                $associated->parent_expense_id = null;
                $associated->save();
            }

            foreach ($folded->splits as $split) {
                $split->delete();
            }

            $folded->delete();
        });

        $survivor->searchable();
    }
}
