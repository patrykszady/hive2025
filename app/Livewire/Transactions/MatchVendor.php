<?php

namespace App\Livewire\Transactions;

use App\Http\Controllers\TransactionController;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Models\TransactionBulkMatch;
use App\Services\VendorSuggestionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class MatchVendor extends Component
{
    use AuthorizesRequests;

    public $vendors = [];

    public $expense_receipt_merchants = [];

    public $merchant_names = [];

    public $match_merchant_names = [];

    public $match_expense_merchant_names = [];

    public $match_vendor_names = [];

    // transaction id => "City, ST" — plain array so it survives Livewire
    // hydration (virtual select columns don't).
    public $txn_locations = [];

    // card index => AI suggestion payload
    public $ai_suggestions = [];

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

    public function mount()
    {
        $this->vendors = Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name', 'city', 'state'])
            ->orderBy('business_name', 'ASC')
            ->get();
        $this->loadExpenseReceiptMerchants();
        $this->loadMerchantNames();
        $this->match_vendor_names = Transaction::transactionsSinVendor()
            ->select(['id', 'plaid_merchant_name'])
            ->get()
            ->groupBy('plaid_merchant_name')
            ->values()
            ->toArray();
    }

    public function updated($field)
    {
        $this->validateOnly($field);
    }

    protected function loadExpenseReceiptMerchants(): void
    {
        $this->expense_receipt_merchants = Expense::withoutGlobalScopes()
            ->with(['receipts' => fn ($query) => $query->latest('id')])
            ->whereNull('deleted_at')
            ->where('vendor_id', 0)
            ->get()
            ->each(function ($expense): void {
                $receipt = $expense->receipts->first();

                if (is_array($receipt?->receipt_items) && isset($receipt->receipt_items['merchant_name'])) {
                    $expense->merchant_name = $receipt->receipt_items['merchant_name'];
                }
            })
            ->groupBy('merchant_name')
            ->toBase();
    }

    protected function loadMerchantNames(): void
    {
        $this->merchant_names = Transaction::transactionsSinVendor()
            ->select([
                'id',
                'amount',
                'transaction_date',
                'plaid_merchant_description',
                'plaid_merchant_name',
                'bank_account_id',
                // Lean location columns — selecting the whole details JSON
                // would serialize every Plaid payload into the Livewire
                // snapshot. On MySQL a JSON null arrives as the string
                // "null"; the blade filters it out.
                'details->location->city as plaid_city',
                'details->location->region as plaid_region',
            ])
            ->with([
                'bank_account' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'bank_id', 'type'])->with([
                    'bank' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'vendor_id', 'name'])->with([
                        'vendor' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'business_name']),
                    ]),
                ]),
            ])
            ->orderBy('plaid_merchant_description')
            ->get()
            ->each(function ($transaction): void {
                // MySQL JSON null arrives as the string "null" — filter both.
                $parts = collect([$transaction->plaid_city, $transaction->plaid_region])
                    ->filter(fn ($part) => filled($part) && $part !== 'null');

                if ($parts->isNotEmpty()) {
                    $this->txn_locations[$transaction->id] = $parts->implode(', ');
                }
            })
            ->groupBy('plaid_merchant_description')
            ->toBase();
    }

    /**
     * Ask the AI (web-search grounded) who the merchant behind this card's
     * descriptor is. Result renders as a suggestion the user can accept.
     */
    public function suggestVendor(int $index, VendorSuggestionService $service)
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $descriptor = (string) collect($this->merchant_names)->keys()->get($index, '');

        if ($descriptor === '') {
            return;
        }

        $transactions = Transaction::transactionsSinVendor()
            ->where('plaid_merchant_description', $descriptor)
            // withoutGlobalScopes at EVERY nesting level — a scoped nested
            // 'bank' load would drop sibling companies' bank names.
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
     * Accept an AI suggestion: an existing vendor fills the form for review;
     * a new vendor is created with the AI's details (name, website, city)
     * plus a matching alias, then transactions are linked.
     */
    public function applySuggestion(int $index)
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $suggestion = $this->ai_suggestions[$index] ?? null;

        if (! is_array($suggestion) || isset($suggestion['error'])) {
            return;
        }

        // An existing vendor still needs a match rule written, exactly like a new
        // one. The first version of this only assigned vendor_id to the form
        // array and returned, so "Use this vendor" looked like it did nothing:
        // no VendorTransaction, no attach, no re-match, no redirect — and
        // because the page never reloaded, the same suggestion card stayed on
        // screen. Both branches now differ only in how the vendor is obtained.
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
        // get the store number stripped first. Falling all the way through
        // matters: with no desc there is no rule, and the click would silently
        // do nothing again.
        $typed = $this->match_merchant_names[$index]['match_desc'] ?? null;

        $matchDesc = filled($typed)
            ? $typed
            : collect([
                $suggestion['match_desc'] ?? null,
                collect($this->merchant_names)->keys()->get($index),
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

        //USED IN MULTIPLE OF PLACES MatchVendor@store, TransactionController@add_vendor_to_transactions
        if (! collect($this->vendors)->pluck('id')->contains($vendor->id)) {
            auth()->user()->vendor->vendors()->attach($vendor->id);
        }

        app(TransactionController::class)->add_vendor_to_transactions();

        return redirect(route('transactions.match_vendor'));
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

        // Never return an empty pattern — a descriptor that is nothing BUT a
        // store number is better matched whole than not at all.
        return filled(trim((string) $stripped)) ? trim((string) $stripped) : trim($descriptor);
    }

    public function store_expense_vendors()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        foreach ($this->match_expense_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                //new Retail Vendor
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);

                $vendor_id = $vendor->id;
                foreach ($this->expense_receipt_merchants[$vendor_match['match_desc']] as $expense) {
                    $expense->vendor_id = $vendor_id;
                    $expense->save();
                }
            } else {
                $deposit_check = null;
                $vendor_id = $vendor_match['vendor_id'];

                $institution_id = null;
                $options = json_encode('/i');

                $vendor_transaction = VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => $deposit_check,
                    'desc' => $vendor_match['match_desc'],
                    'plaid_inst_id' => $institution_id,
                    'options' => $options,
                ]);
            }

            //USED IN MULTIPLE OF PLACES TransactionController@add_vendor_to_transactions, ExpesnesForm@createExpenseFromTransaction
            //add if vendor is not part of the currently logged in vendor
            if (! in_array($vendor_id, $this->vendors->pluck('id')->toArray())) {
                auth()->user()->vendor->vendors()->attach($vendor_id);
            }
        }

        //6-8-2022 run in a queue?
        app(\App\Http\Controllers\TransactionController::class)->add_transaction_to_expenses_sin_vendor();

        return redirect(route('transactions.match_vendor'));
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        foreach ($this->match_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                //new Retail Vendor
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);

                $vendor_id = $vendor->id;
            } else {
                if ($vendor_match['vendor_id'] == 'DEPOSIT') {
                    $deposit_check = 1;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'CHECK') {
                    $deposit_check = 2;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'TRANSFER') {
                    $deposit_check = 3;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'CASH') {
                    $deposit_check = 4;
                    $vendor_id = null;
                } else {
                    $deposit_check = null;
                    $vendor_id = $vendor_match['vendor_id'];
                }

                if (isset($vendor_match['bank_specific'])) {
                    $institution_id = $this->merchant_names->values()[$key][0]['bank_account']['bank']['plaid_ins_id'];
                } else {
                    $institution_id = null;
                }

                if (isset($vendor_match['options'])) {
                    $options = json_encode($vendor_match['options'].'/i');
                } else {
                    $options = json_encode('/i');
                }

                $vendor_transaction = VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => $deposit_check,
                    'amount_sign' => $vendor_match['amount_sign'] ?? null,
                    'desc' => str_replace('*', "\*", $vendor_match['match_desc']),
                    'plaid_inst_id' => $institution_id,
                    'options' => $options,
                ]);
            }

            //USED IN MULTIPLE OF PLACES TransactionController@add_vendor_to_transactions, ExpesnesForm@createExpenseFromTransaction
            //add if vendor is not part of the currently logged in vendor
            if (! in_array($vendor_id, $this->vendors->pluck('id')->toArray())) {
                auth()->user()->vendor->vendors()->attach($vendor_id);
            }
        }

        //add vendor to transaction ...

        //6-8-2022 run in a queue?
        app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();
        app(\App\Http\Controllers\TransactionController::class)->add_check_deposit_to_transactions();

        return redirect(route('transactions.match_vendor'));
    }

    #[Title('Match Transaction/Vendor')]
    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        return view('livewire.transactions.match-vendor', [
            'merchant_names' => $this->merchant_names,
            'vendors' => $this->vendors,
        ]);
    }
}
