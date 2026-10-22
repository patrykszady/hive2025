<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\LinkTransactionRequest;
use App\Http\Resources\Api\App\ExpenseResource;
use App\Models\Expense;
use App\Models\Transaction;
use App\Services\Expenses\TransactionLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manually linking an expense to a bank charge — mirrors
 * App\Livewire\Expenses\LinkTransaction, sharing
 * App\Services\Expenses\TransactionLinkService's candidate search and write
 * logic with it so both write identically. See that Livewire component's
 * docblock for why this exists (an invoice that differs from what was
 * charged never matches the automatic matcher).
 */
class TransactionLinkController extends Controller
{
    public function __construct(private TransactionLinkService $links)
    {
    }

    /** Unlinked bank charges near $expense's date/vendor/amount — mirrors LinkTransaction::candidates(). */
    public function options(Request $request, int $expense): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);
        $this->authorize('update', $found);

        return response()->json([
            'data' => $this->links->candidates($found)->map(fn (Transaction $transaction) => $this->candidatePayload($transaction))->values()->all(),
        ]);
    }

    /** Links the chosen transaction to $expense — mirrors LinkTransaction::link(). */
    public function store(LinkTransactionRequest $request, int $expense): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);
        $this->authorize('update', $found);

        $this->links->link($found, (int) $request->validated('transaction_id'));

        return response()->json(['data' => (new ExpenseResource($this->reloaded($found)))->withDetail()->toArray($request)]);
    }

    /**
     * @return array{id: int, date: ?string, amount: float, bank: ?string, description: ?string}
     */
    private function candidatePayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'amount' => (float) $transaction->amount,
            'bank' => trim(($transaction->bank_account?->bank?->name ?? '').' '.($transaction->bank_account?->account_number ?? '')) ?: null,
            'vendor' => $transaction->vendor_id ? $transaction->vendor?->name : null,
            'description' => $transaction->plaid_merchant_name ?: $transaction->plaid_merchant_description,
        ];
    }

    /** Everything ExpenseResource::withDetail() needs, after the link may have changed it. */
    private function reloaded(Expense $expense): Expense
    {
        return $expense->refresh()->load([
            'vendor', 'project', 'distribution',
            'splits.project:id,project_name', 'splits.distribution:id,name',
            'receipts' => fn ($q) => $q->orderByDesc('id'),
            'checks.bank_account.bank', 'check.bank_account.bank',
        ]);
    }

    /** An expense the person may see: 404 when it doesn't exist, 403 when it isn't theirs (ExpensePolicy::view()). */
    private function visibleExpense(Request $request, int $id): Expense
    {
        abort_unless(Expense::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $found = Expense::withoutGlobalScopes()->whereKey($id)->first();
        abort_unless($request->user()->can('view', $found), 403, 'You do not have access to this expense.');

        return $found;
    }
}
