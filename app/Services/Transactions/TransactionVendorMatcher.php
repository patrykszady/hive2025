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
 * plus applying an AI suggestion (MatchVendor::applySuggestion()). Getting
 * the suggestion itself (VendorSuggestionService::suggest(), an outbound
 * web-search-grounded AI call) is not this class's job — only writing what
 * the human accepts.
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

    /**
     * Accept an AI suggestion — the app API's equivalent of
     * App\Livewire\Transactions\MatchVendor::applySuggestion(): an existing
     * vendor is reused, a new one is created with the AI's details, then a
     * match rule is written and the card's transactions linked. $descriptor
     * is the card's own key (already resolved by the caller — on the web
     * that is $this->merchantCards->keys()->get($index), which can come
     * back '' for a card the listing excludes, e.g. a paper check); it is
     * also the fallback source for $matchDesc when neither a typed value
     * nor the suggestion supplies one.
     *
     * Deliberately only runs add_vendor_to_transactions() (not also
     * add_check_deposit_to_transactions(), unlike matchMerchantCard()) —
     * MatchVendor::applySuggestion() never ran the check/deposit sweep
     * either, since a suggestion is never a deposit/check/transfer/cash
     * code.
     *
     * @param  array{vendor_name: string, existing_vendor_id: ?int, website?: ?string, city?: ?string, state?: ?string, match_desc?: ?string, confidence?: string, reasoning?: string}  $suggestion
     * @return array{vendor: Vendor, match_desc: ?string}
     */
    public function applySuggestion(User $user, string $descriptor, array $suggestion, ?string $typedMatchDesc): array
    {
        $company = $user->vendor;

        $vendor = $this->resolveVendorFromSuggestion($suggestion);

        // What future transactions get matched on. A typed "Match As" wins
        // outright and is used verbatim — someone chose those characters. The
        // machine-generated options (the AI's pattern, then the raw descriptor)
        // get the store number stripped first.
        $matchDesc = filled($typedMatchDesc)
            ? $typedMatchDesc
            : collect([
                $suggestion['match_desc'] ?? null,
                $descriptor,
            ])
                ->map(fn ($value) => $this->stripStoreNumber((string) $value))
                ->first(fn ($value) => filled($value));

        if (filled($matchDesc)) {
            VendorTransaction::create([
                'vendor_id' => $vendor->id,
                'deposit_check' => null,
                'desc' => str_replace('*', "\*", $matchDesc),
                'plaid_inst_id' => null,
                'options' => json_encode('/i'),
            ]);
        }

        $this->linkVendorToCompany($vendor, $company);

        app(TransactionController::class)->add_vendor_to_transactions();

        return ['vendor' => $vendor, 'match_desc' => $matchDesc];
    }

    /**
     * Find or create the vendor an AI suggestion points to: an id it names,
     * else a case-insensitive business-name match (duplicate guard — the
     * suggestion may predate a vendor created since), else a brand-new
     * Retail vendor with the AI's details.
     *
     * @param  array{vendor_name: string, existing_vendor_id: ?int, website?: ?string, city?: ?string, state?: ?string}  $suggestion
     */
    private function resolveVendorFromSuggestion(array $suggestion): Vendor
    {
        $vendor = null;
        if (! empty($suggestion['existing_vendor_id'])) {
            $vendor = Vendor::withoutGlobalScopes()->find($suggestion['existing_vendor_id']);
        }

        $vendor ??= Vendor::withoutGlobalScopes()
            ->whereRaw('LOWER(business_name) = ?', [mb_strtolower(trim($suggestion['vendor_name']))])
            ->first();

        $vendor ??= Vendor::create([
            'business_name' => $suggestion['vendor_name'],
            'business_type' => 'Retail',
            'business_website' => $suggestion['website'] ?? null,
            'city' => $suggestion['city'] ?? null,
            'state' => $suggestion['state'] ?? null,
        ]);

        return $vendor;
    }

    /**
     * Drop a card descriptor's trailing store number.
     *
     * "CITY-MARKET #0430" describes one store; matching on it would leave every
     * other City Market unmatched, and each new location would need its own
     * rule. "CITY-MARKET" catches the chain.
     *
     * Only a trailing "#<digits>" is removed, and deliberately nothing else. A
     * bare trailing number is NOT a store number often enough to touch: the
     * unmatched transactions here include "CHECK 1378" and "Deposit by
     * CheckMobile REF: 492821733", where stripping the digits would collapse
     * every cheque in the account onto a single rule.
     */
    private function stripStoreNumber(string $descriptor): string
    {
        $stripped = preg_replace('/\s*#\s*\d+\s*$/', '', $descriptor);

        return filled(trim((string) $stripped)) ? trim((string) $stripped) : trim($descriptor);
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
