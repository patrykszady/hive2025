<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CheckCharge;

/**
 * Turns an unmatched card charge into an expense on a project or
 * distribution, the way ExpenseForm::store() does it for "Create Expense
 * from Transaction" when no check is involved: the charge's amount, date and
 * vendor, the acting user's company, and the charge linked straight to the
 * new expense. Used by the Transactions card's bulk "Link to project" on
 * /expenses (App\Livewire\Expenses\ExpenseCreate::linkBulkTransactions()).
 */
class TransactionExpenseLinker
{
    /**
     * Whether a charge can be linked in bulk: it names a vendor and is a card
     * charge. Checks, transfers and cash go through the single form, which
     * writes their check.
     */
    public function canLink(Transaction $transaction): bool
    {
        return $transaction->vendor_id !== null
            && ! CheckCharge::isCheck($transaction->check_number, $transaction->plaid_merchant_description);
    }

    public function link(Transaction $transaction, ?int $projectId, ?int $distributionId, User $user): Expense
    {
        $expense = Expense::create([
            'amount' => $transaction->amount,
            'date' => $transaction->transaction_date->format('Y-m-d'),
            'project_id' => $projectId,
            'distribution_id' => $distributionId,
            'vendor_id' => $transaction->vendor_id,
            'belongs_to_vendor_id' => $user->vendor->id,
            'created_by_user_id' => $user->id,
        ]);

        $transaction->expense_id = $expense->id;
        $transaction->manualExpenseLink = true;
        $transaction->save();

        return $expense;
    }
}
