<?php

namespace App\Services\Expenses;

use App\Models\Check;
use App\Models\Expense;
use App\Scopes\VendorScope;
use Illuminate\Support\Collection;

/**
 * "Linked Expenses" (extracted from App\Livewire\Expenses\ExpensesAssociated)
 * and detaching an expense from a check it was paid by (extracted from
 * App\Livewire\Expenses\ExpenseShow::removeFromCheck()) — shared by those
 * Livewire components and the Hive Contractors app's
 * ExpenseAssociationController so both write exactly the same way.
 */
class ExpenseAssociationService
{
    /** Most expenses a search shows. */
    public const SEARCH_LIMIT = 30;

    /** Most suggestions shown (newest first); a vendor like Home Depot has thousands of expenses. */
    public const SUGGESTION_LIMIT = 40;

    /**
     * Suggested matches for linking to $expense: the same vendor (newest
     * first, whenever), or the same amount (either sign) within three
     * months — mirrors ExpensesAssociated::addAssociatedExpense().
     *
     * @return Collection<int, Expense>
     */
    public function suggestions(Expense $expense): Collection
    {
        $excludedIds = $expense->associated()->pluck('id')->toArray();
        $excludedIds[] = $expense->id;

        return Expense::query()
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($expense) {
                $query->where(function ($q) use ($expense) {
                    $q->where(function ($amountQuery) use ($expense) {
                        $amountQuery->where('amount', $expense->amount)
                            ->orWhere('amount', -1 * $expense->amount);
                    })
                        ->whereBetween('date', [
                            $expense->date->copy()->subMonths(3),
                            $expense->date->copy()->addMonths(3),
                        ]);
                })
                    ->orWhere(function ($q) use ($expense) {
                        $q->where('vendor_id', $expense->vendor_id);
                    });
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::SUGGESTION_LIMIT)
            ->get();
    }

    /**
     * Every expense matching $term, newest first — a number matches an id
     * or an amount (either sign); text matches the vendor's name or the
     * invoice. Mirrors ExpensesAssociated::candidates().
     *
     * @return Collection<int, Expense>
     */
    public function search(Expense $expense, string $term): Collection
    {
        $term = trim($term);
        $excludedIds = $expense->associated()->pluck('id')->all();
        $excludedIds[] = $expense->id;

        $number = is_numeric(str_replace([',', '$'], '', $term)) ? (float) str_replace([',', '$'], '', $term) : null;

        return Expense::query()
            ->with('vendor')
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($term, $number) {
                if ($number !== null) {
                    $query->where('id', (int) $number)
                        ->orWhere('amount', $number)
                        ->orWhere('amount', -1 * $number);
                }

                $query->orWhereHas('vendor', fn ($vendor) => $vendor->withoutGlobalScope(VendorScope::class)->where('business_name', 'like', "%{$term}%"))
                    ->orWhere('invoice', 'like', "%{$term}%");
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();
    }

    /**
     * Groups $expense with $associate under one parent_expense_id (whichever
     * of the two groups' roots $associate already belongs to) — mirrors
     * ExpensesAssociated::save().
     */
    public function associate(Expense $expense, Expense $associate): void
    {
        $current = $expense->fresh();

        $associateRootId = $associate->parent_expense_id ?: $associate->id;
        $currentRootId = $current->parent_expense_id ?: $current->id;

        if ($associateRootId !== $currentRootId) {
            $currentGroupIds = Expense::query()
                ->where(function ($query) use ($currentRootId) {
                    $query->where('id', $currentRootId)
                        ->orWhere('parent_expense_id', $currentRootId);
                })
                ->pluck('id');

            if ($currentGroupIds->isNotEmpty()) {
                Expense::query()
                    ->whereIn('id', $currentGroupIds)
                    ->where('id', '!=', $associateRootId)
                    ->update(['parent_expense_id' => $associateRootId]);
            }
        } elseif ($current->id !== $associateRootId) {
            $current->parent_expense_id = $associateRootId;
            $current->save();
        }
    }

    /**
     * The check(s) $expense is linked to (the legacy check_id FK and/or the
     * check_expense pivot), deduplicated — mirrors the `$linkedChecks`
     * computed inline in resources/views/livewire/expenses/show.blade.php
     * for the "Remove from Check" menu.
     *
     * @return Collection<int, Check>
     */
    public function linkedChecks(Expense $expense): Collection
    {
        $linkedChecks = $expense->checks->isNotEmpty() ? $expense->checks : collect();

        if ($expense->check) {
            $linkedChecks = $linkedChecks->concat(collect([$expense->check]));
        }

        return $linkedChecks->unique('id')->values();
    }

    /**
     * Other expenses paid by the same check(s) as $expense, via both the
     * legacy expenses.check_id FK and the check_expense pivot — mirrors
     * ExpenseShow::checkAssociatedExpenses().
     *
     * @return Collection<int, Expense>
     */
    public function checkAssociatedExpenses(Expense $expense): Collection
    {
        $checkIds = $expense->checks()->pluck('checks.id')
            ->when($expense->check_id, fn ($ids) => $ids->push($expense->check_id))
            ->unique()
            ->values();

        if ($checkIds->isEmpty()) {
            return collect();
        }

        return Expense::query()
            ->where('id', '!=', $expense->id)
            ->where(function ($query) use ($checkIds) {
                $query->whereIn('check_id', $checkIds)
                    ->orWhereHas('checks', fn ($q) => $q->whereIn('checks.id', $checkIds));
            })
            ->with('project')
            ->orderBy('date')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * Detaches $expense from $check (both the legacy check_id FK and the
     * check_expense pivot) and recalculates the check's amount — mirrors
     * ExpenseShow::removeFromCheck().
     */
    public function removeFromCheck(Expense $expense, Check $check): void
    {
        if ((int) $expense->check_id === (int) $check->id) {
            $expense->check_id = null;
            $expense->save();
        }

        if ($expense->checks()->where('checks.id', $check->id)->exists()) {
            $expense->checks()->detach($check->id);
            $expense->searchable();
        }

        // Reimbursement deductions must stay negative — use the shared recalc.
        $check->recalculateAmount();
    }
}
