<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\BankResource;
use App\Models\Bank;
use App\Services\Banks\BankAccountsGrouper;
use Illuminate\Http\JsonResponse;

/**
 * The app's Banks tab — the same list and detail as the web's
 * App\Livewire\Banks\{BankIndex,BankShow}: accounts, balances, sync errors
 * and the account's uncleared checks. Reconnecting a bank (Plaid Link),
 * downloading statements and the "Update Bank Account"/"New Bank Account"
 * flows all need the Plaid Link SDK in a browser, so they stay web-only —
 * the app offers "Open in Hive" for those instead. This controller never
 * writes to Plaid or triggers a sync.
 */
class BankController extends Controller
{
    public function index(BankAccountsGrouper $grouper): JsonResponse
    {
        $this->authorize('viewAny', Bank::class);

        $banks = Bank::whereNotNull('plaid_access_token')->get();
        $accountsByBank = $grouper->forBankIds($banks->pluck('id'));

        return response()->json([
            'data' => $banks->map(
                fn (Bank $bank) => (new BankResource($bank, $accountsByBank->get($bank->id, collect())))->toArray(request())
            )->all(),
        ]);
    }

    public function show(int $bank, BankAccountsGrouper $grouper): JsonResponse
    {
        $this->authorize('viewAny', Bank::class);

        $found = Bank::whereKey($bank)->first();
        abort_unless($found, 404);

        $this->authorize('view', $found);

        return response()->json([
            'data' => (new BankResource($found, $grouper->forBank($found->id)))->withDetail()->toArray(request()),
        ]);
    }
}
