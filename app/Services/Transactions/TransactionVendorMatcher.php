<?php

namespace App\Services\Transactions;

use App\Http\Controllers\TransactionController;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;

/**
 * Matches one merchant card (or receipt-expense card) to a vendor — the app
 * API's equivalent of a single row of
 * App\Livewire\Transactions\MatchVendor::store()/store_expense_vendors(),
 * minus the AI-suggestion path (VendorSuggestionService; web-only — it
 * makes an outbound web-search-grounded AI call, too heavy for a quick
 * phone action) and minus "Match As" editing (the app matches on the raw
 * descriptor/merchant name only).
 *
 * The matching ALGORITHM itself — which existing transactions a new rule
 * sweeps up — is not reimplemented here: both this service and MatchVendor
 * call the same shared App\Http\Controllers\TransactionController methods
 * for that. This class only writes the rule row and links the vendor to
 * the signed-in company, exactly as MatchVendor's own store() does.
 *
 * MatchVendor::linkVendorToCompany() itself is left in place rather than
 * extracted here — tests/Feature/Security/T2/MatchVendorScopingSecurityTest
 * invokes it by name through reflection on a bare `new MatchVendor()`, so
 * moving it would break that coverage. The guard it runs
 * (Vendor::isLinkableBy()) is a model method, so this service calls that
 * same guard directly instead of duplicating its logic.
 */
class TransactionVendorMatcher
{
    /**
     * @param  array{vendor_id: string, match_desc: string, amount_sign?: ?int, bank_specific?: bool}  $input
     */
    public function matchMerchantCard(User $user, string $descriptor, array $input): ?Vendor
    {
        $company = $user->vendor;
        $matchDesc = trim((string) $input['match_desc']);

        if (($input['vendor_id'] ?? null) === 'NEW') {
            $vendor = Vendor::create([
                'business_type' => 'Retail',
                'business_name' => $matchDesc,
            ]);

            $this->linkVendorToCompany($vendor, $company);
            $this->runTransactionSync();

            return $vendor;
        }

        [$depositCheck, $vendorId] = match ($input['vendor_id']) {
            'DEPOSIT' => [1, null],
            'CHECK' => [2, null],
            'TRANSFER' => [3, null],
            'CASH' => [4, null],
            default => [null, (int) $input['vendor_id']],
        };

        $institutionId = null;
        if (! empty($input['bank_specific'])) {
            $sample = Transaction::transactionsSinVendor()
                ->where('plaid_merchant_description', $descriptor)
                ->with(['bank_account' => fn ($query) => $query->withoutGlobalScopes()
                    ->with(['bank' => fn ($query) => $query->withoutGlobalScopes()])])
                ->first();

            $institutionId = $sample?->bank_account?->bank?->plaid_ins_id;
        }

        VendorTransaction::create([
            'vendor_id' => $vendorId,
            'deposit_check' => $depositCheck,
            'amount_sign' => $input['amount_sign'] ?? null,
            'desc' => str_replace('*', "\*", $matchDesc),
            'plaid_inst_id' => $institutionId,
            'options' => json_encode('/i'),
        ]);

        $vendor = $vendorId ? Vendor::withoutGlobalScopes()->find($vendorId) : null;
        $this->linkVendorToCompany($vendor, $company);
        $this->runTransactionSync();

        return $vendor;
    }

    /**
     * @param  array{vendor_id: string, match_desc: string}  $input
     */
    public function matchExpenseCard(User $user, \Illuminate\Support\Collection $expenses, array $input): ?Vendor
    {
        $company = $user->vendor;
        $matchDesc = trim((string) $input['match_desc']);

        if (($input['vendor_id'] ?? null) === 'NEW') {
            $vendor = Vendor::create([
                'business_type' => 'Retail',
                'business_name' => $matchDesc,
            ]);

            foreach ($expenses as $expense) {
                $expense->vendor_id = $vendor->id;
                $expense->save();
            }
        } else {
            $vendorId = (int) $input['vendor_id'];

            VendorTransaction::create([
                'vendor_id' => $vendorId,
                'deposit_check' => null,
                'desc' => $matchDesc,
                'plaid_inst_id' => null,
                'options' => json_encode('/i'),
            ]);

            $vendor = Vendor::withoutGlobalScopes()->find($vendorId);
        }

        $this->linkVendorToCompany($vendor, $company);

        app(TransactionController::class)->add_transaction_to_expenses_sin_vendor();

        return $vendor;
    }

    private function linkVendorToCompany(?Vendor $vendor, ?Vendor $company): void
    {
        if (! $vendor || ! $vendor->isLinkableBy($company)) {
            return;
        }

        $company->vendors()->syncWithoutDetaching([$vendor->id]);
    }

    private function runTransactionSync(): void
    {
        app(TransactionController::class)->add_vendor_to_transactions();
        app(TransactionController::class)->add_check_deposit_to_transactions();
    }
}
