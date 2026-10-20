<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ApplySuggestionRequest;
use App\Http\Requests\Api\App\MatchExpenseVendorRequest;
use App\Http\Requests\Api\App\MatchTransactionVendorRequest;
use App\Http\Requests\Api\App\SuggestVendorRequest;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Services\Transactions\TransactionVendorMatcher;
use App\Services\Transactions\UnmatchedTransactionCards;
use App\Services\VendorSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The app's "Match Vendor" global action — the same unmatched-transaction
 * and unmatched-receipt cards as App\Livewire\Transactions\MatchVendor,
 * the same single-card match action (existing vendor, a new retail
 * vendor, or a non-vendor code like Deposit/Transfer), and the same
 * AI-suggestion flow (suggestVendor()/applySuggestion(): ask
 * VendorSuggestionService who the merchant is, then accept or skip it).
 * On the web this sits under "Global Actions", visible only to Patryk
 * (UserPolicy::admin_login_as_user) — every endpoint here still authorizes
 * against TransactionBulkMatchPolicy::viewAny (any Admin), the same gate
 * MatchVendor's own render()/store() run, so the app's visibility (its
 * More-tab row, client-side) should match the web sidebar and stay
 * Patryk-only even though the API itself allows any Admin.
 */
class TransactionMatchController extends Controller
{
    public const VENDOR_OPTIONS_CACHE_SECONDS = 3600;
    public function unmatched(UnmatchedTransactionCards $cards, Request $request): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $user = $request->user();

        return response()->json([
            'data' => [
                'merchant_cards' => $cards->merchantCards($user)->map(fn ($transactions, $descriptor) => [
                    'descriptor' => (string) $descriptor,
                    'count' => $transactions->count(),
                    'total' => round((float) $transactions->sum('amount'), 2),
                    'bank' => $transactions->first()?->bank_account?->bank?->name,
                    'location' => $transactions->first()?->card_location,
                    'transactions' => $transactions->map(fn (Transaction $transaction) => [
                        'id' => $transaction->id,
                        'amount' => (float) $transaction->amount,
                        'date' => $transaction->transaction_date?->toDateString(),
                        // Per-row Location/Bank | Type/Company — mirrors
                        // match-vendor.blade.php's own transaction table
                        // columns exactly (each row can differ even within
                        // one merchant card, e.g. two cardholders' banks).
                        'location' => $transaction->card_location !== '' ? $transaction->card_location : null,
                        'bank' => $transaction->bank_account?->bank?->name,
                        'type' => $transaction->bank_account?->type,
                        'company' => $transaction->bank_account?->bank?->vendor?->business_name,
                    ])->values()->all(),
                ])->values()->all(),
                'expense_cards' => $cards->expenseCards($user)->map(fn ($expenses, $merchantName) => [
                    'merchant_name' => (string) $merchantName,
                    'count' => $expenses->count(),
                    'total' => round((float) $expenses->sum('amount'), 2),
                    'expenses' => $expenses->map(fn (Expense $expense) => [
                        'id' => $expense->id,
                        'amount' => (float) $expense->amount,
                        'date' => $expense->date?->toDateString(),
                    ])->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }

    public function match(MatchTransactionVendorRequest $request, TransactionVendorMatcher $matcher): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $vendor = $matcher->matchMerchantCard($request->user(), (string) $request->validated('descriptor'), [
            'vendor_id' => $request->validated('vendor_id'),
            'match_desc' => $request->validated('match_desc'),
            'amount_sign' => $request->validated('amount_sign'),
            'bank_specific' => (bool) $request->validated('bank_specific'),
        ]);

        return response()->json([
            'data' => [
                'vendor' => $vendor ? ['id' => $vendor->id, 'business_name' => $vendor->business_name] : null,
            ],
        ]);
    }

    public function matchExpense(MatchExpenseVendorRequest $request, UnmatchedTransactionCards $cards, TransactionVendorMatcher $matcher): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $merchantName = (string) $request->validated('merchant_name');
        $expenses = $cards->expenseCards($request->user())->get($merchantName, collect());

        abort_if($expenses->isEmpty(), 404, 'No unmatched expenses found for this merchant.');

        $vendor = $matcher->matchExpenseCard($request->user(), $expenses, [
            'vendor_id' => $request->validated('vendor_id'),
            'match_desc' => $request->validated('match_desc'),
        ]);

        return response()->json([
            'data' => [
                'vendor' => $vendor ? ['id' => $vendor->id, 'business_name' => $vendor->business_name] : null,
            ],
        ]);
    }

    /**
     * Ask the AI who the merchant behind this card's descriptor is — the
     * same data MatchVendor::suggestVendor() gathers (every transaction
     * sharing the descriptor, plus every vendor as AI context) and the
     * same VendorSuggestionService call, so both get the same answer (the
     * service's own cache is keyed on the descriptor alone).
     */
    public function suggestVendor(SuggestVendorRequest $request, VendorSuggestionService $service): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $descriptor = (string) $request->validated('descriptor');

        $transactions = Transaction::transactionsSinVendor()
            ->where('plaid_merchant_description', $descriptor)
            ->with(['bank_account' => fn ($query) => $query->withoutGlobalScopes()
                ->with(['bank' => fn ($query) => $query->withoutGlobalScopes()])])
            ->get();

        $vendors = Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name', 'city', 'state'])
            ->get();

        $suggestion = $service->suggest($descriptor, $transactions, $vendors);

        return response()->json([
            'data' => $suggestion ?? ['error' => 'Could not identify this merchant — try again or match manually.'],
        ]);
    }

    /**
     * Accept a suggestion returned by suggestVendor() — the app API's
     * equivalent of MatchVendor::applySuggestion(), writing the exact same
     * rows via the shared TransactionVendorMatcher::applySuggestion().
     */
    public function applySuggestion(ApplySuggestionRequest $request, TransactionVendorMatcher $matcher): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $result = $matcher->applySuggestion(
            $request->user(),
            (string) $request->validated('descriptor'),
            $request->validated('suggestion'),
            $request->validated('match_desc'),
        );

        return response()->json([
            'data' => [
                'vendor' => [
                    'id' => $result['vendor']->id,
                    'business_name' => $result['vendor']->business_name,
                    'created' => $result['vendor']->wasRecentlyCreated,
                ],
                'match_desc' => $result['match_desc'],
            ],
        ]);
    }

    /**
     * Every vendor, for the card's vendor picker — the app's own copy of
     * the same "every vendor, unscoped" list MatchVendor's vendor-picker
     * loads with :all="true" (see App\Http\Controllers\VendorPickerOptionsController
     * and ExpenseVendorOptionsController's docblocks for why each section
     * keeps its own copy of this shape rather than sharing one endpoint).
     * Unlike those, there is no "mine"/"all" split here: this whole
     * controller is already Admin-only (viewAny on TransactionBulkMatch),
     * same as the web page that embeds the picker.
     */
    public function vendorOptions(): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $vendors = Cache::remember(
            'app:transactions:vendor-options:'.$this->vendorOptionsFingerprint(),
            self::VENDOR_OPTIONS_CACHE_SECONDS,
            fn () => $this->vendorOptionRows(),
        );

        return response()->json(['data' => $vendors]);
    }

    /** Changes whenever a vendor is added, removed or edited. */
    private function vendorOptionsFingerprint(): string
    {
        $row = Vendor::withoutGlobalScopes()->selectRaw('COUNT(*) AS total, MAX(updated_at) AS updated')->first();

        return md5(($row->total ?? 0).'|'.($row->updated ?? ''));
    }

    /**
     * @return list<array{id: int, name: string, legal: string, city: ?string, state: ?string, type: ?string}>
     */
    private function vendorOptionRows(): array
    {
        return Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name', 'city', 'state', 'business_type'])
            ->orderBy('business_name')
            ->get()
            ->map(fn (Vendor $vendor) => [
                'id' => (int) $vendor->id,
                'name' => (string) $vendor->name,
                'legal' => (string) $vendor->business_name,
                'city' => $vendor->city ?: null,
                'state' => $vendor->state ?: null,
                'type' => $vendor->business_type ?: null,
            ])
            ->values()
            ->all();
    }
}
