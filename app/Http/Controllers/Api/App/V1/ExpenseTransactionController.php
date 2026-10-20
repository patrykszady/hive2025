<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\UnmatchedTransactionResource;
use App\Models\Transaction;
use App\Services\Expenses\AppExpenseDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's "Transactions" card under Expenses — mirrors
 * App\Livewire\Expenses\ExpenseIndex::transactions() (every unmatched bank
 * row, including person transfers with no check — Venmo/Zelle/share
 * transfers stay visible until a check or an expense claims them) and
 * ExpenseCreate::createExpenseFromTransaction()'s pre-fill, via
 * App\Services\Expenses\AppExpenseDirectory /
 * App\Services\Expenses\UnmatchedTransactionFinder.
 */
class ExpenseTransactionController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private AppExpenseDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'vendor' => $request->query('vendor'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'amount' => $request->query('amount'),
        ];

        $page = $this->directory->unmatchedTransactionsQuery($request->user(), $filters)
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Transaction $transaction) => (new UnmatchedTransactionResource($transaction))->toArray($request))->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** Pre-fill payload for "create an expense from this transaction" — 404s once it's no longer unmatched (claimed by a concurrent request) rather than letting a stale row be acted on twice. */
    public function show(Request $request, int $transaction): JsonResponse
    {
        $user = $request->user();
        $found = $this->directory->unmatchedTransaction($user, $transaction);
        abort_unless($found, 404);

        return response()->json(['data' => $this->directory->transactionPrefill($found)]);
    }
}
