<?php

namespace App\Livewire\Transactions;

use App\Http\Controllers\TransactionController;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\VendorSuggestionService;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Global Actions → Match Vendor.
 *
 * Only FORM state lives on the component. Everything the page displays is a
 * computed property, re-read per request. The previous version carried every
 * unmatched transaction (with relations), every receipt expense and all 800+
 * vendors as public properties — serialised into the snapshot and hydrated
 * back on every keystroke — and printed the whole vendor list as options in
 * every card. That was a 17MB page that stalled before all cards had painted.
 * Each card's vendor picker now searches the server and shows one scroll-page
 * of matches at a time.
 */
class MatchVendor extends Component
{
    use AuthorizesRequests;

    /** Options a vendor picker shows per scroll-page. */
    public const VENDOR_OPTION_LIMIT = 25;

    public $match_merchant_names = [];

    public $match_expense_merchant_names = [];

    // card index => AI suggestion payload
    public $ai_suggestions = [];

    /** picker key ("txn_0", "exp_2") => what was typed into its search box */
    public array $vendor_search = [];

    /** picker key => how many rows the open dropdown has scrolled to */
    public array $vendor_limit = [];

    /** Per-request memo so a card's options query runs once, not once per use in the view. */
    protected array $vendorOptionsMemo = [];

    public $view_text = [
        'card_title' => 'Save Transactions/Vendor',
        'button_text' => 'Sync Transactions & Vendors',
        'form_submit' => 'store',
    ];

    protected function rules()
    {
        return [
            'match_merchant_names.*.match_desc' => 'required',
            'match_merchant_names.*.vendor_id' => 'required',
            'match_expense_merchant_names.*.match_desc' => 'required',
            'match_expense_merchant_names.*.vendor_id' => 'required',
        ];
    }

    public function updated($field)
    {
        // A new search term restarts that picker's paging from the top.
        if (str_starts_with($field, 'vendor_search.')) {
            unset($this->vendor_limit[substr($field, strlen('vendor_search.'))]);

            return;
        }

        if (str_starts_with($field, 'vendor_limit.')) {
            return;
        }

        $this->validateOnly($field);
    }

    /**
     * Unmatched transactions grouped by card descriptor, in card order.
     * Indexes in the match forms are positions in this collection.
     */
    #[Computed]
    public function merchantCards(): Collection
    {
        return Transaction::transactionsSinVendor()
            ->select([
                'id',
                'amount',
                'transaction_date',
                'plaid_merchant_description',
                'plaid_merchant_name',
                'bank_account_id',
                // Lean location columns — the whole details JSON would drag
                // every Plaid payload through the render. On MySQL a JSON null
                // arrives as the string "null"; filtered below.
                'details->location->city as plaid_city',
                'details->location->region as plaid_region',
            ])
            ->with([
                // withoutGlobalScopes at EVERY nesting level — a scoped nested
                // load would drop sibling companies' bank names.
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
     * Receipt expenses with no vendor, grouped by the receipt's merchant name.
     * A COMPUTED key: stamping merchant_name onto the Expense models made the
     * later save() write a column that does not exist.
     */
    #[Computed]
    public function expenseCards(): Collection
    {
        return Expense::withoutGlobalScopes()
            ->with(['receipts' => fn ($query) => $query->latest('id')])
            ->whereNull('deleted_at')
            ->where('vendor_id', 0)
            ->get()
            ->groupBy(function (Expense $expense): string {
                $receipt = $expense->receipts->first();

                return is_array($receipt?->receipt_items) && isset($receipt->receipt_items['merchant_name'])
                    ? (string) $receipt->receipt_items['merchant_name']
                    : '';
            })
            ->toBase();
    }

    /**
     * The options one card's vendor picker shows: the typed search (or the
     * alphabetical head of the list), one scroll-page at a time, plus whatever
     * that card already has selected so the picker can still label it.
     */
    public function vendorOptions(string $key): Collection
    {
        $term = trim((string) ($this->vendor_search[$key] ?? ''));
        $selected = $this->selectedVendorId($key);
        $limit = $this->vendorLimitFor($key);

        // Keyed on everything that shapes the list, so a changed term or a
        // scrolled page within the same request never serves stale options.
        $memo = implode('|', [$key, $term, $limit, (string) $selected]);
        if (isset($this->vendorOptionsMemo[$memo])) {
            return $this->vendorOptionsMemo[$memo];
        }

        $columns = ['id', 'business_name', 'city', 'state'];

        $options = Vendor::withoutGlobalScopes()
            ->select($columns)
            ->when($term !== '', fn ($query) => $query->where('business_name', 'like', "%{$term}%"))
            ->orderBy('business_name')
            ->limit($limit)
            ->get();

        if ($selected !== null && ! $options->contains('id', $selected)) {
            $vendor = Vendor::withoutGlobalScopes()->select($columns)->find($selected);
            if ($vendor) {
                $options->prepend($vendor);
            }
        }

        return $this->vendorOptionsMemo[$memo] = $options;
    }

    /**
     * The picker's search box calls this (debounced) instead of binding
     * wire:model: a bound input inside a re-rendering island had its value
     * written back from the server mid-typing, eating keystrokes ("mnard").
     * With a method call the typed text lives only in the DOM.
     */
    public function searchVendors(string $key, string $term): void
    {
        $this->vendor_search[$key] = $term;
        unset($this->vendor_limit[$key]);
    }

    /** True while more vendors exist beyond what the open dropdown has shown. */
    public function hasMoreVendorOptions(string $key): bool
    {
        return $this->vendorOptions($key)->count() >= $this->vendorLimitFor($key);
    }

    /** Scrolling the dropdown's sentinel into view asks for the next page. */
    public function loadMoreVendorOptions(string $key): void
    {
        $this->vendor_limit[$key] = $this->vendorLimitFor($key) + self::VENDOR_OPTION_LIMIT;
    }

    protected function vendorLimitFor(string $key): int
    {
        return max(self::VENDOR_OPTION_LIMIT, (int) ($this->vendor_limit[$key] ?? self::VENDOR_OPTION_LIMIT));
    }

    /** The numeric vendor a card's form has chosen — NEW/DEPOSIT/CHECK/... are static options, not vendors. */
    protected function selectedVendorId(string $key): ?int
    {
        [$scope, $index] = array_pad(explode('_', $key, 2), 2, null);
        $form = $scope === 'exp' ? $this->match_expense_merchant_names : $this->match_merchant_names;
        $value = $form[(int) $index]['vendor_id'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Forget everything the last render knew, without a page load.
     *
     * The display collections are computed per request, so this only drops
     * their caches; the form arrays are positional and must be cleared, or a
     * card's typed values would apply to whichever merchant slid into its
     * index once one disappeared from the list.
     */
    protected function refreshLists(): void
    {
        unset($this->merchantCards, $this->expenseCards);
        $this->vendorOptionsMemo = [];

        $this->ai_suggestions = [];
        $this->match_merchant_names = [];
        $this->match_expense_merchant_names = [];
        $this->vendor_search = [];
        $this->vendor_limit = [];
        $this->resetValidation();
    }

    /**
     * Make a vendor visible to the signed-in company. The vendors page is
     * scoped to this pivot, so a vendor matched here but never linked shows
     * up nowhere — that is how two "Art Of Vision" rows went missing.
     * Idempotent: an already-linked vendor is left alone.
     */
    protected function linkVendorToCompany(mixed $vendorId): void
    {
        $company = auth()->user()?->vendor;

        if ($company && is_numeric($vendorId) && (int) $vendorId > 0) {
            $company->vendors()->syncWithoutDetaching([(int) $vendorId]);
        }
    }

    /**
     * Ask the AI (web-search grounded) who the merchant behind this card's
     * descriptor is. Result renders as a suggestion the user can accept.
     */
    public function suggestVendor(int $index, VendorSuggestionService $service)
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $descriptor = (string) $this->merchantCards->keys()->get($index, '');
        if ($descriptor === '') {
            return;
        }

        $transactions = Transaction::transactionsSinVendor()
            ->where('plaid_merchant_description', $descriptor)
            ->with(['bank_account' => fn ($q) => $q->withoutGlobalScopes()
                ->with(['bank' => fn ($q) => $q->withoutGlobalScopes()])])
            ->get();

        $vendors = Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name', 'city', 'state'])
            ->get();

        $suggestion = $service->suggest($descriptor, $transactions, $vendors);

        $this->ai_suggestions[$index] = $suggestion
            ?? ['error' => 'Could not identify this merchant — try again or match manually.'];
    }

    /**
     * Accept an AI suggestion: an existing vendor is reused, a new one is
     * created with the AI's details, then a match rule is written and the
     * transactions linked — both branches differ only in how the vendor is
     * obtained.
     */
    public function applySuggestion(int $index)
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $suggestion = $this->ai_suggestions[$index] ?? null;
        if (! is_array($suggestion) || isset($suggestion['error'])) {
            return;
        }

        $vendor = null;
        if (! empty($suggestion['existing_vendor_id'])) {
            $vendor = Vendor::withoutGlobalScopes()->find($suggestion['existing_vendor_id']);
        }

        // Duplicate guard: the suggestion may predate a vendor created since
        // (stale cache, another tab, a colleague) — reuse it instead. Also
        // catches an existing_vendor_id that has since been deleted.
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

        // What future transactions get matched on. A typed "Match As" wins
        // outright and is used verbatim — someone chose those characters. The
        // machine-generated options (the AI's pattern, then the raw descriptor)
        // get the store number stripped first.
        $typed = $this->match_merchant_names[$index]['match_desc'] ?? null;
        $matchDesc = filled($typed)
            ? $typed
            : collect([
                $suggestion['match_desc'] ?? null,
                $this->merchantCards->keys()->get($index),
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

        $this->linkVendorToCompany($vendor->id);

        app(TransactionController::class)->add_vendor_to_transactions();

        $this->refreshLists();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $vendor->wasRecentlyCreated ? 'Vendor created and matched.' : 'Vendor matched.',
            text: $vendor->business_name.(filled($matchDesc) ? ' — future "'.$matchDesc.'" transactions will match automatically.' : ''),
        );
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
    protected function stripStoreNumber(string $descriptor): string
    {
        $stripped = preg_replace('/\s*#\s*\d+\s*$/', '', $descriptor);

        return filled(trim((string) $stripped)) ? trim((string) $stripped) : trim($descriptor);
    }

    /**
     * The receipt expenses behind one row of the "match as" form.
     *
     * Rows are indexed positionally over the merchant-keyed cards, while
     * "Match As" is free text the user edits before saving — so resolve by
     * position, and by name only as a courtesy when it still matches.
     */
    protected function expenseRowMerchants(int|string $row, ?string $matchDesc): iterable
    {
        $merchants = $this->expenseCards;

        if (filled($matchDesc) && $merchants->has($matchDesc)) {
            return $merchants->get($matchDesc);
        }

        return $merchants->values()->get((int) $row) ?? [];
    }

    public function store_expense_vendors()
    {
        $this->validate();

        foreach ($this->match_expense_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);

                $vendor_id = $vendor->id;
                foreach ($this->expenseRowMerchants($key, $vendor_match['match_desc']) as $expense) {
                    $expense->vendor_id = $vendor_id;
                    $expense->save();
                }
            } else {
                $vendor_id = $vendor_match['vendor_id'];

                VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => null,
                    'desc' => $vendor_match['match_desc'],
                    'plaid_inst_id' => null,
                    'options' => json_encode('/i'),
                ]);
            }

            $this->linkVendorToCompany($vendor_id);
        }

        app(TransactionController::class)->add_transaction_to_expenses_sin_vendor();

        $saved = count($this->match_expense_merchant_names);
        $this->refreshLists();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $saved === 1 ? 'Expense vendor saved.' : $saved.' expense vendors saved.',
            text: '',
        );
    }

    public function store()
    {
        $this->validate();

        foreach ($this->match_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);
                $vendor_id = $vendor->id;
            } else {
                [$deposit_check, $vendor_id] = match ($vendor_match['vendor_id']) {
                    'DEPOSIT' => [1, null],
                    'CHECK' => [2, null],
                    'TRANSFER' => [3, null],
                    'CASH' => [4, null],
                    default => [null, $vendor_match['vendor_id']],
                };

                // "Bank specific": the rule applies only to this card's bank.
                $institution_id = isset($vendor_match['bank_specific'])
                    ? $this->merchantCards->values()->get($key)?->first()?->bank_account?->bank?->plaid_ins_id
                    : null;

                $options = isset($vendor_match['options'])
                    ? json_encode($vendor_match['options'].'/i')
                    : json_encode('/i');

                VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => $deposit_check,
                    'amount_sign' => $vendor_match['amount_sign'] ?? null,
                    'desc' => str_replace('*', "\*", $vendor_match['match_desc']),
                    'plaid_inst_id' => $institution_id,
                    'options' => $options,
                ]);
            }

            $this->linkVendorToCompany($vendor_id);
        }

        app(TransactionController::class)->add_vendor_to_transactions();
        app(TransactionController::class)->add_check_deposit_to_transactions();

        $saved = count($this->match_merchant_names);
        $this->refreshLists();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $saved === 1 ? 'Transaction matched.' : $saved.' transactions matched.',
            text: '',
        );
    }

    #[Title('Match Transaction/Vendor')]
    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        return view('livewire.transactions.match-vendor');
    }
}
