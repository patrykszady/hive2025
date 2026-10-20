<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Expenses\ReceiptLineItemDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A receipt line item's detail (price history and product image) — mirrors
 * the web's item-detail modal, fed by App\Livewire\Expenses\ExpenseShow::
 * selectReceiptItem() via the shared App\Services\Expenses\
 * ReceiptLineItemDetail. The web hides unit price/total from a client
 * browsing session (auth()->user()->is_browsing_as_client); the app has no
 * such session, but the same check is kept here for parity.
 */
class ExpenseReceiptItemController extends Controller
{
    public function show(Request $request, int $expense, int $receipt, int $index, ReceiptLineItemDetail $detail): JsonResponse
    {
        $found = $this->visibleExpense($request, $expense);

        $item = $detail->forItem($found, $receipt, $index);

        abort_if($item === null, 404, 'That line item could not be found.');

        if ($request->user()->is_browsing_as_client) {
            unset($item['Price'], $item['TotalPrice']);
        }

        return response()->json(['data' => $item]);
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
