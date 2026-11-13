<?php

namespace App\Services\Transactions;

use App\Http\Controllers\TransactionController;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Support\Transactions\MatchRuleDuplicates;
use App\Support\Transactions\MatchTextReach;
use App\Support\Transactions\MerchantIdentity;
use App\Support\Transactions\PaymentProcessors;
use Illuminate\Validation\ValidationException;

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
 * One rule it refuses to write, on every path, is one that names nothing
 * but a payment processor ("PADDLE.NET", "PAYPAL *"): such a rule claims
 * every company billed through that processor (how two DataForSEO charges
 * ended up under Laravel Daily). The card's MerchantIdentity decides, from
 * the descriptor and Plaid's payload, and supplies the merchant text to
 * match on instead.
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
     *
     * @throws ValidationException when the match text names only a payment processor
     */
    public function matchMerchantCard(User $user, string $descriptor, array $input): ?Vendor
    {
        $company = $user->vendor;
        $matchDesc = trim((string) $input['match_desc']);
        $vendorId = $input['vendor_id'] ?? null;
        $identity = MerchantIdentity::forDescriptor($descriptor);

        // Non-vendor codes (Deposit, Check, Transfer, Cash) never claim a
        // vendor, so only a vendor match is held to the processor rule.
        if (($vendorId === 'NEW' || is_numeric($vendorId)) && ($refusal = $identity->refusal($matchDesc)) !== null) {
            throw ValidationException::withMessages(['match_desc' => $refusal]);
        }

        // A rule is tried on the bank's own text and on Plaid's name for the
        // charge: one that reaches neither on the card it is saved from would
        // match nothing (MatchTextReach).
        $names = Transaction::transactionsSinVendor()->where('plaid_merchant_description', $descriptor)->pluck('plaid_merchant_name')->all();
        if (filled($vendorId) && ($refusal = MatchTextReach::refusal($descriptor, $matchDesc, null, $vendorId !== 'NEW' && ! is_numeric($vendorId), $names)) !== null) {
            throw ValidationException::withMessages(['match_desc' => $refusal]);
        }

        // One rule per text, bank and sign, and one vendor per name
        // (MatchRuleDuplicates) — as the web form refuses them.
        if ($vendorId === 'NEW') {
            $newName = $identity->hasProcessor() ? $identity->vendorNameFor($matchDesc) : $matchDesc;
            if (($refusal = MatchRuleDuplicates::newVendorRefusal($newName)) !== null) {
                throw ValidationException::withMessages(['vendor_id' => $refusal]);
            }
        }
        if (filled($vendorId)) {
            $depositCheck = ['DEPOSIT' => 1, 'CHECK' => 2, 'TRANSFER' => 3, 'CASH' => 4][$vendorId] ?? null;
            $institution = ! empty($input['bank_specific'])
                ? Transaction::transactionsSinVendor()->where('plaid_merchant_description', $descriptor)
                    ->with(['bank_account' => fn ($query) => $query->withoutGlobalScopes()->with(['bank' => fn ($query) => $query->withoutGlobalScopes()])])
                    ->first()?->bank_account?->bank?->plaid_ins_id
                : null;
            $sign = filled($input['amount_sign'] ?? null) ? (int) $input['amount_sign'] : null;

            if (($refusal = MatchRuleDuplicates::refusal($matchDesc, is_numeric($vendorId) ? (int) $vendorId : null, $depositCheck, $institution, $sign)) !== null) {
                throw ValidationException::withMessages(['match_desc' => $refusal]);
            }
        }

        if ($vendorId === 'NEW') {
            $vendor = Vendor::create([
                'business_type' => 'Retail',
                'business_name' => trim($identity->hasProcessor() ? $identity->vendorNameFor($matchDesc) : $matchDesc),
            ]);

            // The Match As text becomes the new vendor's rule, as it does for
            // an existing vendor (behind a processor it is the merchant part,
            // "DATAFORSE"). Left to be found by its name alone, a new vendor
            // whose name is not the bank's or Plaid's text never claimed its
            // card (MatchVendor::store(), 2026-11-13).
            $this->writeRule($vendor->id, $matchDesc);

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
     *
     * @throws ValidationException when the match text names only a payment processor
     */
    public function matchExpenseCard(User $user, \Illuminate\Support\Collection $expenses, array $input): ?Vendor
    {
        $company = $user->vendor;
        $matchDesc = trim((string) $input['match_desc']);

        // A receipt names no bank descriptor, so only the curated list can
        // tell; the rule written here feeds the transaction sweep too.
        if (PaymentProcessors::isProcessorOnly($matchDesc)) {
            throw ValidationException::withMessages(['match_desc' => PaymentProcessors::refusalMessage($matchDesc)]);
        }

        if (($input['vendor_id'] ?? null) === 'NEW') {
            if (($refusal = MatchRuleDuplicates::newVendorRefusal($matchDesc)) !== null) {
                throw ValidationException::withMessages(['vendor_id' => $refusal]);
            }
        } elseif (($existing = MatchRuleDuplicates::existing($matchDesc)->first()) !== null && (int) $existing->vendor_id !== (int) $input['vendor_id']) {
            throw ValidationException::withMessages(['match_desc' => MatchRuleDuplicates::refusal($matchDesc, (int) $input['vendor_id'])]);
        }

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

            if (MatchRuleDuplicates::existing($matchDesc)->isEmpty()) {
                VendorTransaction::create([
                    'vendor_id' => $vendorId,
                    'deposit_check' => null,
                    'desc' => $matchDesc,
                    'plaid_inst_id' => null,
                    'options' => json_encode('/i'),
                ]);
            }

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
     *
     * @throws ValidationException when a typed match text names only a payment processor
     */
    public function applySuggestion(User $user, string $descriptor, array $suggestion, ?string $typedMatchDesc): array
    {
        $company = $user->vendor;
        $identity = MerchantIdentity::forDescriptor($descriptor);

        if (filled($typedMatchDesc) && ($refusal = $identity->refusal((string) $typedMatchDesc)) !== null) {
            throw ValidationException::withMessages(['match_desc' => $refusal]);
        }

        $vendor = $this->resolveVendorFromSuggestion($suggestion);

        // What future transactions get matched on. A typed "Match As" wins
        // outright and is used verbatim — someone chose those characters. The
        // machine-generated options (the AI's pattern, then the raw descriptor)
        // are made safe first: see machineMatchDesc().
        $matchDesc = filled($typedMatchDesc)
            ? $typedMatchDesc
            : collect([
                $suggestion['match_desc'] ?? null,
                $descriptor,
            ])
                ->map(fn ($value) => $this->machineMatchDesc((string) $value, $identity))
                ->first(fn ($value) => filled($value));

        if (filled($matchDesc)) {
            $this->writeRule($vendor->id, $matchDesc);
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
     * A machine-generated match text made safe to write. A processor alone
     * (the AI answering "PADDLE.NET" for a Paddle charge, or a bare
     * processor descriptor) becomes the card's merchant match text, or
     * nothing; a processor descriptor ("PADDLE.NET* DATAFORSEO") becomes its
     * merchant match text ("DATAFORSE"); anything else loses its store
     * number.
     */
    private function machineMatchDesc(string $value, MerchantIdentity $identity): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($identity->isProcessorOnlyRule($value)) {
            return $identity->matchText;
        }

        $split = PaymentProcessors::split($value);
        if ($split['processor'] !== null && $split['merchant'] !== null) {
            return PaymentProcessors::matchText($value) ?? $split['merchant'];
        }

        return $this->stripStoreNumber($value);
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

    /** The plain vendor rule every path here writes, "*" escaped as the web form does. */
    private function writeRule(int $vendorId, string $matchDesc): void
    {
        // Never the same rule twice (MatchRuleDuplicates).
        if (MatchRuleDuplicates::existing($matchDesc)->contains(fn (VendorTransaction $rule): bool => (int) $rule->vendor_id === $vendorId)) {
            return;
        }

        VendorTransaction::create([
            'vendor_id' => $vendorId,
            'deposit_check' => null,
            'desc' => str_replace('*', "\*", $matchDesc),
            'plaid_inst_id' => null,
            'options' => json_encode('/i'),
        ]);
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
