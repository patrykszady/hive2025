<?php

namespace App\Livewire\Transactions;

use App\Http\Controllers\TransactionController;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\Transactions\TransactionVendorMatcher;
use App\Services\Transactions\UnmatchedTransactionCards;
use App\Services\VendorSuggestionService;
use App\Support\Transactions\MerchantIdentity;
use App\Support\Transactions\PaymentProcessors;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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


    public $match_merchant_names = [];

    public $match_expense_merchant_names = [];

    // card index => AI suggestion payload
    public $ai_suggestions = [];




    public $view_text = [
        'card_title' => 'Save Transactions/Vendor',
        'button_text' => 'Sync Transactions & Vendors',
        'form_submit' => 'store',
    ];

    /**
     * A card is in play once either box holds something, and then needs
     * both. A card with "Match As" and Vendor both empty is simply left for
     * later — never an error (Patryk, 2026-11-12) — so one saves the cards
     * one knows and leaves the rest.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'match_merchant_names.*.match_desc' => 'required_with:match_merchant_names.*.vendor_id',
            'match_merchant_names.*.vendor_id' => 'required_with:match_merchant_names.*.match_desc',
            'match_expense_merchant_names.*.match_desc' => 'required_with:match_expense_merchant_names.*.vendor_id',
            'match_expense_merchant_names.*.vendor_id' => 'required_with:match_expense_merchant_names.*.match_desc',
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        $needsMatchText = 'Type what to match on, or clear the vendor to leave this card for later.';
        $needsVendor = 'Pick a vendor, or clear Match As to leave this card for later.';

        return [
            'match_merchant_names.*.match_desc.required_with' => $needsMatchText,
            'match_merchant_names.*.vendor_id.required_with' => $needsVendor,
            'match_expense_merchant_names.*.match_desc.required_with' => $needsMatchText,
            'match_expense_merchant_names.*.vendor_id.required_with' => $needsVendor,
        ];
    }

    /**
     * The rows of a "match as" form that take part in a save, keys (card
     * positions) kept. Left out: a card with no vendor and nothing typed —
     * and a processor card still holding only the "Match As" it was
     * prefilled with ($prefills, by position), which nobody typed. A row
     * with just one box filled in stays, for validation to name what is
     * missing.
     *
     * @param  array<int|string, mixed>  $rows
     * @param  array<int|string, ?string>  $prefills
     * @return array<int|string, array<string, mixed>>
     */
    protected function rowsInPlay(array $rows, array $prefills = []): array
    {
        return array_filter($rows, function (mixed $row, int|string $key) use ($prefills): bool {
            if (! is_array($row)) {
                return false;
            }
            if (filled($row['vendor_id'] ?? null)) {
                return true;
            }

            $typed = trim((string) ($row['match_desc'] ?? ''));

            return $typed !== '' && $typed !== trim((string) ($prefills[$key] ?? ''));
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function updated($field)
    {
        $this->validateOnly($field);
    }

    /**
     * Unmatched transactions grouped by card descriptor, in card order.
     * Indexes in the match forms are positions in this collection.
     */
    #[Computed]
    public function merchantCards(): Collection
    {
        return app(UnmatchedTransactionCards::class)->merchantCards(auth()->user());
    }

    /**
     * Receipt expenses with no vendor, grouped by the receipt's merchant name.
     * A COMPUTED key: stamping merchant_name onto the Expense models made the
     * later save() write a column that does not exist.
     */
    #[Computed]
    public function expenseCards(): Collection
    {
        return app(UnmatchedTransactionCards::class)->expenseCards(auth()->user());
    }

    /**
     * The cards billed through a payment processor (Paddle, Square, Toast,
     * PayPal… from the descriptor prefix or Plaid's payload), by card index:
     * a note for under "Match As" and the merchant match text to propose
     * ("DATAFORSE" for "PADDLE.NET* DATAFORSEO" — cut to the bank's window so
     * it claims the truncated form too).
     *
     * Reading it also prefills an empty "Match As" with that text. The form
     * arrays are positional over the computed cards and the cards render
     * inside a lazy island, so the render that draws a card is the one
     * moment that knows its index — the view reads this in each card's
     * @php block. A typed value is never overwritten.
     *
     * `plaid_name_is_processor` tells the card to leave Plaid's merchant name
     * out from under its heading: on these charges Plaid often names the
     * processor as the merchant ("Paddle" on a DataForSEO charge), and the
     * processor is not who was paid (Patryk, 2026-11-12).
     *
     * @return array<int, array{note: string, match_desc: ?string, plaid_name_is_processor: bool}>
     */
    #[Computed]
    public function processorCards(): array
    {
        $cards = [];
        foreach ($this->merchantCards->values() as $index => $transactions) {
            $descriptor = (string) ($this->merchantCards->keys()->get($index) ?? '');
            $identity = MerchantIdentity::forCard($descriptor, $transactions);
            if (! $identity->hasProcessor()) {
                continue;
            }

            if ($identity->matchText !== null && blank($this->match_merchant_names[$index]['match_desc'] ?? null)) {
                $this->match_merchant_names[$index]['match_desc'] = $identity->matchText;
            }

            $cards[$index] = [
                'note' => (string) $identity->note(),
                'match_desc' => $identity->matchText,
                'plaid_name_is_processor' => $identity->isProcessorName($transactions->first()?->plaid_merchant_name),
            ];
        }

        return $cards;
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
        unset($this->merchantCards, $this->expenseCards, $this->processorCards);

        $this->ai_suggestions = [];
        $this->match_merchant_names = [];
        $this->match_expense_merchant_names = [];
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

        if (! $company || ! is_numeric($vendorId) || (int) $vendorId <= 0) {
            return;
        }

        $vendor = Vendor::withoutGlobalScopes()->find((int) $vendorId);

        // Never silently attach another tenant's own registered company —
        // $ai_suggestions is client-writable, so existing_vendor_id cannot
        // be trusted without this check.
        if (! $vendor || ! $vendor->isLinkableBy($company)) {
            return;
        }

        $company->vendors()->syncWithoutDetaching([$vendor->id]);
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
     * transactions linked. The actual resolution and writes are
     * TransactionVendorMatcher::applySuggestion() — the app API's
     * TransactionMatchController::applySuggestion() accepts the same
     * suggestion shape and calls the same method, so both write identical
     * rows.
     */
    public function applySuggestion(int $index)
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $suggestion = $this->ai_suggestions[$index] ?? null;
        if (! is_array($suggestion) || isset($suggestion['error'])) {
            return;
        }

        $descriptor = (string) $this->merchantCards->keys()->get($index, '');
        $typed = $this->match_merchant_names[$index]['match_desc'] ?? null;

        $result = app(TransactionVendorMatcher::class)->applySuggestion(auth()->user(), $descriptor, $suggestion, $typed);
        $vendor = $result['vendor'];
        $matchDesc = $result['match_desc'];

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
        $this->match_expense_merchant_names = $this->rowsInPlay($this->match_expense_merchant_names);
        $this->validate();

        // A receipt names no bank descriptor, so only the curated list can
        // tell a processor-only match text; the rule written here feeds
        // the transaction sweep too.
        $refusals = [];
        foreach ($this->match_expense_merchant_names as $key => $vendor_match) {
            if (PaymentProcessors::isProcessorOnly((string) $vendor_match['match_desc'])) {
                $refusals["match_expense_merchant_names.{$key}.match_desc"] = PaymentProcessors::refusalMessage((string) $vendor_match['match_desc']);
            }
        }
        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

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

    /**
     * Validates the cards in play and returns each one's merchant identity,
     * by card position.
     *
     * A rule naming only a payment processor ("PADDLE.NET", "PAYPAL *")
     * would claim every company billed through it: refused before anything
     * is written. Non-vendor codes (Deposit, Check, Transfer, Cash) never
     * claim a vendor and are let through. The card's identity (descriptor
     * prefix plus Plaid's payload) decides and names the merchant text.
     *
     * @return array<int|string, MerchantIdentity>
     *
     * @throws ValidationException
     */
    protected function validatedCardIdentities(): array
    {
        $this->validate();

        $identities = [];
        $refusals = [];
        foreach ($this->match_merchant_names as $key => $vendor_match) {
            $identities[$key] = MerchantIdentity::forDescriptor((string) $this->merchantCards->keys()->get($key, ''));
            $vendorId = $vendor_match['vendor_id'];
            if (($vendorId === 'NEW' || is_numeric($vendorId)) && ($refusal = $identities[$key]->refusal((string) $vendor_match['match_desc'])) !== null) {
                $refusals["match_merchant_names.{$key}.match_desc"] = $refusal;
            }
        }
        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        return $identities;
    }

    public function store()
    {
        // The rows as submitted, taken before processorCards is read: reading
        // it fills an empty "Match As" back in, and a box the person emptied
        // must stay empty here.
        $submitted = $this->match_merchant_names;
        $prefills = array_map(fn (array $card): ?string => $card['match_desc'], $this->processorCards);
        $this->match_merchant_names = $this->rowsInPlay($submitted, $prefills);

        try {
            $identities = $this->validatedCardIdentities();
        } catch (ValidationException $e) {
            // The page keeps every box as the person left it, the cards left
            // out of this save included.
            $this->match_merchant_names = $submitted;

            throw $e;
        }

        foreach ($this->match_merchant_names as $key => $vendor_match) {
            $identity = $identities[$key];
            if ($vendor_match['vendor_id'] == 'NEW') {
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $identity->hasProcessor() ? $identity->vendorNameFor($vendor_match['match_desc']) : $vendor_match['match_desc'],
                ]);
                $vendor_id = $vendor->id;

                // A new vendor is normally found again by its name alone. Behind
                // a processor the descriptor reads "PADDLE.NET* DATAFORSEO", so
                // the merchant match text becomes its rule — cut to the bank's
                // window ("DATAFORSE") it covers the truncated form as well.
                if ($identity->hasProcessor()) {
                    VendorTransaction::create([
                        'vendor_id' => $vendor_id,
                        'deposit_check' => null,
                        'amount_sign' => $vendor_match['amount_sign'] ?? null,
                        'desc' => str_replace('*', "\*", $vendor_match['match_desc']),
                        'plaid_inst_id' => null,
                        'options' => json_encode('/i'),
                    ]);
                }
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
