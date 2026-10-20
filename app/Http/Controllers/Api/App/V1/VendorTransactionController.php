<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreVendorTransactionRequest;
use App\Models\Bank;
use App\Models\TransactionBulkMatch;
use App\Models\VendorTransaction;
use App\Services\Transactions\VendorTransactionRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's bulk-match rules list — the same rows, search, create, edit
 * and delete as the web's App\Livewire\Transactions\{VendorTransactionsPanel,
 * VendorTransactionEditModal}, under the same "Match Vendor" global action.
 */
class VendorTransactionController extends Controller
{
    public function index(Request $request, VendorTransactionRuleService $rules): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $page = $rules->paginate((string) $request->query('search', ''));

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(StoreVendorTransactionRequest $request, VendorTransactionRuleService $rules): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $payload = $rules->buildPayload($request->validated());

        if ($rules->exists($payload)) {
            return response()->json([
                'message' => 'A matching vendor transaction already exists.',
                'errors' => ['desc' => ['A matching vendor transaction already exists.']],
            ], 422);
        }

        $rule = $rules->create($payload);

        return response()->json(['data' => ['id' => $rule->id]], 201);
    }

    public function update(int $vendorTransaction, StoreVendorTransactionRequest $request, VendorTransactionRuleService $rules): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $rule = VendorTransaction::find($vendorTransaction);
        abort_unless($rule, 404);

        $payload = $rules->buildPayload($request->validated());

        if ($rules->exists($payload, $rule->id)) {
            return response()->json([
                'message' => 'A matching vendor transaction already exists.',
                'errors' => ['desc' => ['A matching vendor transaction already exists.']],
            ], 422);
        }

        $rules->update($rule, $payload);

        return response()->json(['data' => ['id' => $rule->id]]);
    }

    public function destroy(int $vendorTransaction, VendorTransactionRuleService $rules): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $rule = VendorTransaction::find($vendorTransaction);
        abort_unless($rule, 404);

        $rules->delete($rule);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * The rule form's searchable "Bank" select — the exact same source as
     * the web's VendorTransactionEditModal::banks() computed (every bank
     * with a Plaid institution id, one row per institution). A rule
     * matches by `plaid_inst_id`, not a bank row id, so the option value
     * is the institution id itself.
     */
    public function bankOptions(): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $banks = Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_ins_id')
            ->orderBy('name')
            ->get()
            ->unique('plaid_ins_id')
            ->values();

        return response()->json([
            'data' => $banks->map(fn (Bank $bank) => [
                'plaid_ins_id' => $bank->plaid_ins_id,
                'name' => $bank->name,
            ])->all(),
        ]);
    }
}
