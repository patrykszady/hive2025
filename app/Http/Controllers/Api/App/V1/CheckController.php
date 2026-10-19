<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\CheckResource;
use App\Models\Bank;
use App\Models\Check;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Checks tab: the same list, filters and detail breakdown as the
 * web (App\Livewire\Checks\ChecksIndex, CheckShow) — see Check's own
 * CheckScope global scope, which already narrows an Admin to their company's
 * checks and a Member to their own payroll/vendor-owned checks, exactly as
 * the web's query does.
 *
 * Creating a check stays web-only: the web's CheckCreate component is only
 * ever opened from an existing expense/vendor-payment flow to EDIT a check
 * (its own store() path is dead code — `dd('in store Check')`), so there is
 * no clean standalone "create a check" flow to mirror. See the detail
 * screen's "Open in Hive" button.
 */
class CheckController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Check::class);

        $checkNumber = trim((string) $request->query('check_number', ''));
        $amount = trim((string) $request->query('amount', ''));
        $bankId = $request->query('bank_id');
        $vendorId = $request->query('vendor_id');

        $query = Check::query()->with(['bank_account.bank', 'vendor', 'user']);

        if ($checkNumber !== '') {
            $query->where(function ($inner) use ($checkNumber) {
                $inner->where('check_number', 'like', "%{$checkNumber}%")
                    ->orWhere('check_type', 'like', "%{$checkNumber}%");
            });
        }

        if ($amount !== '') {
            $query->where('amount', 'like', $amount.'%');
        }

        if (is_numeric($bankId)) {
            $bank = Bank::find((int) $bankId);
            if ($bank) {
                $bankIds = Bank::where('plaid_ins_id', $bank->plaid_ins_id)->pluck('id');
                $query->whereHas('bank_account', fn ($q) => $q->whereIn('bank_id', $bankIds));
            }
        }

        if (is_numeric($vendorId)) {
            $query->where('vendor_id', (int) $vendorId);
        }

        $page = $query->orderByDesc('date')->orderByDesc('id')->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Check $check) => (new CheckResource($check))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, int $check): JsonResponse
    {
        $found = $this->visibleCheck($check);
        $this->authorize('view', $found);

        $found->load(['bank_account.bank', 'vendor', 'user']);

        return response()->json(['data' => (new CheckResource($found))->withDetail()->toArray($request)]);
    }

    /** A check the person may see: 404 when it doesn't exist, 403 when it isn't theirs (via CheckScope). */
    private function visibleCheck(int $id): Check
    {
        abort_unless(Check::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $check = Check::query()->whereKey($id)->first();
        abort_unless($check, 403, 'You do not have access to this check.');

        return $check;
    }
}
