<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreExpenseRequest;
use App\Http\Requests\Api\App\UpdateExpenseRequest;
use App\Http\Resources\Api\App\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\AppExpenseDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The app's Expenses tab: the same list/filters/totals, detail, create,
 * edit and delete as the web Expenses index/show/form — see
 * App\Services\Expenses\AppExpenseDirectory for the shared query and
 * write logic this reuses, and App\Livewire\Expenses\{ExpenseIndex,
 * ExpenseShow,ExpenseCreate} for the web pages it mirrors.
 */
class ExpenseController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private AppExpenseDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'project' => $request->query('project'),
            'vendor' => $request->query('vendor'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'status' => Arr::wrap($request->query('status', [])),
            'amount' => $request->query('amount'),
            'reimbursement' => $request->query('reimbursement'),
            'direction' => $request->query('direction'),
        ];

        $page = $this->directory->query($request->user(), $filters)
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Expense $expense) => (new ExpenseResource($expense))->toArray($request))->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function show(Request $request, int $expense): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);
        $found->load([
            'vendor', 'project', 'distribution',
            'splits.project:id,project_name', 'splits.distribution:id,name',
            'receipts' => fn ($q) => $q->orderByDesc('id'),
            'checks.bank_account.bank', 'check.bank_account.bank',
            'payments',
        ]);

        return response()->json(['data' => (new ExpenseResource($found))->withDetail()->toArray($request)]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $user = $request->user();

        // StoreExpenseRequest::authorize() already refused a non-admin
        // (mirroring ExpenseForm::store()'s authorize('create', ...) before
        // validate()) — see ExpensePolicy::create().
        $result = $this->directory->create($user, $request->validated(), $request->file('receipt'));
        $expense = $result->expense;
        $expense->load(['vendor', 'project', 'distribution', 'splits.project:id,project_name', 'splits.distribution:id,name', 'receipts', 'checks.bank_account.bank', 'check.bank_account.bank', 'payments']);

        return response()->json([
            'data' => (new ExpenseResource($expense))->withDetail()->toArray($request),
            // Mirrors ExpenseForm::store()'s session-flashed warning
            // (ExpenseForm.php:604-611) when a receipt was attached but OCR
            // couldn't process it — the expense itself still saved fine.
            'receipt_warning' => $result->receiptWarning,
        ], 201);
    }

    /**
     * POST, not PUT: a receipt photo makes this a multipart request, and
     * PHP never populates a PUT request's uploaded files the way it does
     * for POST — this sidesteps that rather than fighting it.
     */
    public function update(UpdateExpenseRequest $request, int $expense): JsonResponse
    {
        $user = $request->user();

        // UpdateExpenseRequest::authorize() (inherited from
        // StoreExpenseRequest) already refused a non-admin — mirrors
        // ExpenseForm::update(), which (like store()) authorizes against
        // 'create', not 'update' — see ExpensePolicy::create().
        $found = $this->visibleExpense($request, $expense);
        $result = $this->directory->update($user, $found, $request->validated(), $request->file('receipt'));
        $updated = $result->expense;
        $updated->load(['vendor', 'project', 'distribution', 'splits.project:id,project_name', 'splits.distribution:id,name', 'receipts', 'checks.bank_account.bank', 'check.bank_account.bank', 'payments']);

        return response()->json([
            'data' => (new ExpenseResource($updated))->withDetail()->toArray($request),
            // See store()'s matching comment.
            'receipt_warning' => $result->receiptWarning,
        ]);
    }

    public function destroy(Request $request, int $expense): JsonResponse
    {
        $user = $request->user();
        $found = $this->visibleExpense($request, $expense);

        abort_unless($this->directory->canDelete($user, $found), 403, 'This expense has a matched transaction — only an admin can delete it.');

        $found->deleteWithAssociations();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Bulk-select "Delete selected" — mirrors ExpenseIndex::bulkDelete().
     * Unlike single destroy() this never 403s the whole request: each
     * selected id that the acting user may not delete (a Member's pick
     * that already has a matched transaction) is silently skipped, exactly
     * like the web's toast ("N skipped (admin-only: has transactions)").
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $result = $this->directory->bulkDelete($request->user(), $validated['ids']);

        return response()->json(['data' => $result]);
    }

    /** Mirrors ExpenseIndex::restoreExpense(): Admin-only (ExpensePolicy::restore()), trashed rows only. */
    public function restore(Request $request, int $expense): JsonResponse
    {
        $found = $this->visibleTrashedExpense($request, $expense);
        abort_unless($request->user()->can('restore', $found), 403, 'Only an admin can restore a deleted expense.');

        $restored = $this->directory->restore($found);
        $restored->load(['vendor', 'project', 'distribution', 'splits.project:id,project_name', 'splits.distribution:id,name', 'receipts', 'checks.bank_account.bank', 'check.bank_account.bank', 'payments']);

        return response()->json(['data' => (new ExpenseResource($restored))->withDetail()->toArray($request)]);
    }

    /** An expense the person may see: 404 when it doesn't exist, 403 when it isn't theirs (ExpensePolicy::view(), the richer check ExpenseScope's list filtering approximates). */
    private function visibleExpense(Request $request, int $id): Expense
    {
        abort_unless(Expense::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $found = Expense::withoutGlobalScopes()->whereKey($id)->first();
        abort_unless($request->user()->can('view', $found), 403, 'You do not have access to this expense.');

        return $found;
    }

    /** A soft-deleted expense the person may see — same 404-vs-403 split as visibleExpense(), restricted to trashed rows. */
    private function visibleTrashedExpense(Request $request, int $id): Expense
    {
        $found = Expense::withoutGlobalScopes()->onlyTrashed()->whereKey($id)->first();
        abort_unless($found, 404);
        abort_unless($request->user()->can('view', $found), 403, 'You do not have access to this expense.');

        return $found;
    }
}
