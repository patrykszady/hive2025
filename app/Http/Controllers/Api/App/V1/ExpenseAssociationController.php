<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AssociateExpenseRequest;
use App\Http\Resources\Api\App\ExpenseResource;
use App\Models\Check;
use App\Models\Expense;
use App\Services\Expenses\ExpenseAssociationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Linked Expenses" and removing an expense from a check it was paid by —
 * mirrors App\Livewire\Expenses\{ExpensesAssociated,ExpenseShow}, sharing
 * App\Services\Expenses\ExpenseAssociationService's suggestion/search and
 * write logic with them rather than re-deriving it.
 */
class ExpenseAssociationController extends Controller
{
    public function __construct(private ExpenseAssociationService $associations)
    {
    }

    /**
     * Suggestions when `search` is blank, or anything matching it (an id,
     * an amount either sign, a vendor name, an invoice) once typed —
     * mirrors ExpensesAssociated::addAssociatedExpense()/candidates().
     */
    public function options(Request $request, int $expense): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);

        $search = trim((string) $request->query('search', ''));

        $results = $search === ''
            ? $this->associations->suggestions($found)
            : $this->associations->search($found, $search);

        return response()->json([
            'data' => $results->map(fn (Expense $candidate) => $this->candidatePayload($candidate))->values()->all(),
        ]);
    }

    /** Groups $expense with the chosen candidate — mirrors ExpensesAssociated::save(). */
    public function associate(AssociateExpenseRequest $request, int $expense): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);
        $associate = Expense::findOrFail($request->validated('associate_expense_id'));

        $this->associations->associate($found, $associate);

        return response()->json(['data' => (new ExpenseResource($this->reloaded($found)))->withDetail()->toArray($request)]);
    }

    /** Detaches $expense from $check — mirrors ExpenseShow::removeFromCheck(). */
    public function removeFromCheck(Request $request, int $expense, int $check): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);
        $this->authorize('update', $found);

        $checkModel = Check::findOrFail($check);
        $this->associations->removeFromCheck($found, $checkModel);

        return response()->json(['data' => (new ExpenseResource($this->reloaded($found)))->withDetail()->toArray($request)]);
    }

    /** @return array{id: int, amount: float, date: ?string, vendor: ?string, invoice: ?string} */
    private function candidatePayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'date' => $expense->date?->format('Y-m-d'),
            'vendor' => $expense->vendor?->business_name,
            'invoice' => $expense->invoice,
        ];
    }

    /** Everything ExpenseResource::withDetail() needs, after a write may have changed it. */
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
