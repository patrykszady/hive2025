<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\MatchExpenseVendorRequest;
use App\Http\Requests\Api\App\MatchTransactionVendorRequest;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Services\Transactions\TransactionVendorMatcher;
use App\Services\Transactions\UnmatchedTransactionCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's "Match Vendor" global action — the same unmatched-transaction
 * and unmatched-receipt cards as App\Livewire\Transactions\MatchVendor,
 * and the same single-card match action (existing vendor, a new retail
 * vendor, or a non-vendor code like Deposit/Transfer). The AI-suggestion
 * path (MatchVendor::suggestVendor()/applySuggestion()) stays web-only —
 * it makes an outbound web-search-grounded AI call, not a quick phone
 * action. On the web this sits under "Global Actions", visible only to
 * Patryk (UserPolicy::admin_login_as_user) — every endpoint here still
 * authorizes against TransactionBulkMatchPolicy::viewAny (any Admin), the
 * same gate MatchVendor's own render()/store() run, so the app's visibility
 * (its More-tab row, client-side) should match the web sidebar and stay
 * Patryk-only even though the API itself allows any Admin.
 */
class TransactionMatchController extends Controller
{
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
}
