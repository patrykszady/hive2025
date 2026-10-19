<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Receipts\AppAutoReceiptsQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's "Recent Auto Receipts" queue — mirrors
 * App\Livewire\Expenses\AutoReceipts, reachable from the web's Expenses
 * index behind the same gate this uses (`@can('create', Expense::class)`
 * wraps the "Recent Auto Receipts" button there). Read-only: reviewing or
 * correcting the matched Expense happens on the app's existing Expense
 * edit screen, which this queue only links to.
 */
class AutoReceiptController extends Controller
{
    public function index(Request $request, AppAutoReceiptsQueue $queue): JsonResponse
    {
        $this->authorize('create', Expense::class);

        return response()->json(['data' => $queue->queue($request->user()->vendor?->id)]);
    }

    public function show(Request $request, int $receipt, AppAutoReceiptsQueue $queue): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $found = $queue->detail($receipt, $request->user()->vendor?->id);
        abort_unless($found, 404);

        return response()->json(['data' => $found]);
    }
}
