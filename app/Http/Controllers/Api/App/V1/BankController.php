<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\BankResource;
use App\Models\Bank;
use App\Services\Banks\BankAccountsGrouper;
use App\Services\Banks\LatestStatementDownloader;
use App\Services\PlaidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The app's Banks tab — the same list and detail as the web's
 * App\Livewire\Banks\{BankIndex,BankShow}: accounts, balances, sync errors,
 * the account's uncleared checks, and downloading the latest Plaid
 * statement (statementUrl()). Reconnecting a bank (Plaid Link) and the
 * "Update Bank Account"/"New Bank Account" flows all need the Plaid Link
 * SDK in a browser, so those stay web-only — the app offers "Open in Hive"
 * for those instead.
 *
 * statementUrl() hands back a signed download link rather than the PDF
 * itself, same reasoning as SheetController's exports: the PDF is fetched
 * live from Plaid and rendered once here, while authenticated, then stored
 * under storage/app/exports/bank-statements for a short-lived, session-less
 * fetch — see BankStatementExportController.
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

    /**
     * A signed link to this bank's latest Plaid statement PDF — mirrors
     * App\Livewire\Banks\BankShow::downloadLatestStatement() via the same
     * App\Services\Banks\LatestStatementDownloader it now also calls.
     */
    public function statementUrl(Request $request, int $bank, LatestStatementDownloader $downloader, PlaidService $plaidService): JsonResponse
    {
        $found = Bank::whereKey($bank)->first();
        abort_unless($found, 404);

        // BankShow::downloadLatestStatement() authorizes against
        // Bank::class generically ('create', not 'view') — matched here.
        $this->authorize('create', Bank::class);

        $result = $downloader->download($found, $plaidService);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        $token = (string) Str::uuid();
        Storage::disk('local')->put("exports/bank-statements/{$token}.pdf", $result['binary']);

        $expiresAt = now()->addMinutes(10);

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute('banks.statement.signed', $expiresAt, ['token' => $token, 'filename' => $result['filename']]),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }
}
