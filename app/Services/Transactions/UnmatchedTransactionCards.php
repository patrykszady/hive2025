<?php

namespace App\Services\Transactions;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Unmatched transactions and receipt expenses, grouped by merchant — the
 * two card lists behind App\Livewire\Transactions\MatchVendor
 * (merchantCards()/expenseCards()), shared with the app API's
 * TransactionMatchController so both read the exact same rows.
 */
class UnmatchedTransactionCards
{
    /**
     * Unmatched transactions grouped by card descriptor, in card order.
     */
    public function merchantCards(User $user): Collection
    {
        // transactionsSinVendor() drops Transaction's own tenant scope (it
        // needs to match against every bank's history), so re-apply it
        // here: only the signed-in company's own bank accounts.
        $vendorId = $user->vendor?->id;
        $bankAccountIds = $vendorId
            ? BankAccount::withoutGlobalScopes()->where('vendor_id', $vendorId)->pluck('id')
            : collect();

        return Transaction::transactionsSinVendor()
            ->whereIn('bank_account_id', $bankAccountIds)
            ->select([
                'id',
                'amount',
                'transaction_date',
                'plaid_merchant_description',
                'plaid_merchant_name',
                'bank_account_id',
                // Lean location columns — the whole details JSON would drag
                // every Plaid payload through the render. On MySQL a JSON
                // null arrives as the string "null"; filtered below.
                'details->location->city as plaid_city',
                'details->location->region as plaid_region',
            ])
            ->with([
                // withoutGlobalScopes at EVERY nesting level — a scoped
                // nested load would drop sibling companies' bank names.
                'bank_account' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'bank_id', 'type'])->with([
                    'bank' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'vendor_id', 'name', 'plaid_ins_id'])->with([
                        'vendor' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'business_name']),
                    ]),
                ]),
            ])
            ->orderBy('plaid_merchant_description')
            ->get()
            ->each(function (Transaction $transaction): void {
                $parts = collect([$transaction->plaid_city, $transaction->plaid_region])
                    ->filter(fn ($part) => filled($part) && $part !== 'null');

                // Display-only; these models never leave this request.
                $transaction->setAttribute('card_location', $parts->implode(', '));
            })
            ->groupBy('plaid_merchant_description')
            ->toBase();
    }

    /**
     * Receipt expenses with no vendor, grouped by the receipt's merchant
     * name.
     */
    public function expenseCards(User $user): Collection
    {
        $vendorId = $user->vendor?->id;

        if (! $vendorId) {
            return collect();
        }

        return Expense::withoutGlobalScopes()
            ->with(['receipts' => fn ($query) => $query->latest('id')])
            ->whereNull('deleted_at')
            ->where('vendor_id', 0)
            ->where('belongs_to_vendor_id', $vendorId)
            ->get()
            ->groupBy(function (Expense $expense): string {
                $receipt = $expense->receipts->first();

                return is_array($receipt?->receipt_items) && isset($receipt->receipt_items['merchant_name'])
                    ? (string) $receipt->receipt_items['merchant_name']
                    : '';
            })
            ->toBase();
    }
}
