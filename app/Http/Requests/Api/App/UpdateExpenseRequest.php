<?php

namespace App\Http\Requests\Api\App;

use App\Models\Expense;

/**
 * Editing an expense validates identically to creating one — see
 * StoreExpenseRequest, which this reuses wholesale (mirrors ExpenseForm,
 * whose update() runs the same rules() as store()).
 */
class UpdateExpenseRequest extends StoreExpenseRequest
{
    /**
     * Re-submitting the expense's own already-recorded bank/check number
     * unchanged must not collide with itself in the check_number
     * uniqueness check.
     */
    protected function excludedCheckId(): ?int
    {
        $expenseId = (int) $this->route('expense');

        return Expense::withoutGlobalScopes()->whereKey($expenseId)->first()?->check_id;
    }

    /**
     * See StoreExpenseRequest::expenseIdForSplitOwnership()'s docblock — on
     * update, a submitted split id must belong to THIS route's expense.
     */
    protected function expenseIdForSplitOwnership(): ?int
    {
        return (int) $this->route('expense');
    }
}
