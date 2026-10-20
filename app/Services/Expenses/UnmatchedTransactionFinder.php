<?php

namespace App\Services\Expenses;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * The base query behind the web's Transactions card
 * (App\Livewire\Expenses\ExpenseIndex::transactions(), via
 * Transaction::unmatchedSearchFilters()): every bank row still unaccounted
 * for — nothing with an expense (direct or shared), a check, or a deposit
 * payment. Reimplemented here against Eloquent/SQLite rather than
 * Meilisearch (same reasoning as AppExpenseDirectory's own amount filter —
 * the app's Pest suite has no search index to hit), scoped to the acting
 * user's own vendor's bank accounts exactly like
 * Transaction::scopedSearch()'s bankAccountIds lookup does.
 *
 * Shared by AppExpenseDirectory (listing the Transactions card) and
 * ExpenseAccessGuard (validating a transaction_id before letting "create an
 * expense from this transaction" link it) so both agree on what "still
 * unmatched" means — neither depends on the other, so there is no
 * constructor cycle between them.
 */
class UnmatchedTransactionFinder
{
    public function query(int $vendorId): Builder
    {
        $bankAccountIds = BankAccount::where('vendor_id', $vendorId)->pluck('id');

        return Transaction::query()
            ->whereIn('bank_account_id', $bankAccountIds)
            ->whereNull('expense_id')
            ->whereNull('check_id')
            ->whereDoesntHave('expenses')
            ->whereDoesntHave('payments');
    }

    public function find(int $vendorId, int $transactionId): ?Transaction
    {
        return $this->query($vendorId)->whereKey($transactionId)->first();
    }
}
