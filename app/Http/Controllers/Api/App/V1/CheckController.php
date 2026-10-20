<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateCheckRequest;
use App\Http\Requests\Api\App\UploadCheckImageRequest;
use App\Http\Resources\Api\App\CheckResource;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Services\CheckImageUpload;
use App\Services\Checks\CheckUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
 * no clean standalone "create a check" flow to mirror.
 *
 * Editing a check's own fields (update()) and uploading a check image
 * (uploadImage()) both reuse the exact services the web's CheckShow/
 * CheckForm call (CheckUpdateService, CheckImageUpload), so the app writes
 * identically, down to the cascading side effects a date or payee change
 * triggers (Check::moveDate(), reassigning the vendor on the expenses this
 * check paid). Reconnecting a bank via Plaid Link stays web-only — see
 * BankController.
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

    /**
     * The edit form's bank select — mirrors CheckCreate::mount()'s own
     * $bank_accounts query (Checking accounts whose bank has a live Plaid
     * connection) and getNameAndType()'s label.
     */
    public function bankAccountOptions(): JsonResponse
    {
        $this->authorize('viewAny', Check::class);

        $accounts = BankAccount::with('bank')
            ->where('type', 'Checking')
            ->whereHas('bank', fn ($query) => $query->whereNotNull('plaid_access_token'))
            ->get();

        return response()->json([
            'data' => $accounts->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'name' => $account->getNameAndType(),
            ])->values()->all(),
        ]);
    }

    /**
     * Edits a check's own fields — mirrors App\Livewire\Forms\CheckForm::
     * update() exactly via the App\Services\Checks\CheckUpdateService it
     * also now calls: a new date moves every expense the check dated, and
     * a new payee reassigns onto the expenses this check paid.
     */
    public function update(UpdateCheckRequest $request, int $check, CheckUpdateService $updates): JsonResponse
    {
        $found = $this->visibleCheck($check);
        $this->authorize('update', $found);

        // check_type carries no validation rule on the web either (see
        // CheckUpdateService::rules()'s docblock) — CheckForm::update()
        // reads it straight off the component property, not a validated
        // array, so this does the same from the raw request input.
        $updates->update($found, $request->validated() + ['check_type' => $request->input('check_type')]);

        $found->refresh()->load(['bank_account.bank', 'vendor', 'user']);

        return response()->json(['data' => (new CheckResource($found))->withDetail()->toArray($request)]);
    }

    /** Removes a check — mirrors App\Livewire\Forms\CheckForm::delete(). */
    public function destroy(Request $request, int $check, CheckUpdateService $updates): JsonResponse
    {
        $found = $this->visibleCheck($check);
        $this->authorize('delete', $found);

        $updates->remove($found);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Attach a photo of the check, or the bank's single-check PDF, to it —
     * mirrors CheckShow::uploadCheckImage(), down to the same
     * `check_images` log channel entries (tagged "(app)" to tell the
     * source apart in the log, same reasoning as the check_images incident
     * this channel exists for — see project memory's "Check images lost
     * their links").
     */
    public function uploadImage(UploadCheckImageRequest $request, int $check, CheckImageUpload $upload): JsonResponse
    {
        $found = $this->visibleCheck($check);
        $this->authorize('update', $found);

        $file = $request->file('file');
        $context = [
            'check_id' => $found->id,
            'user_id' => $request->user()->id,
            'file' => $file?->getClientOriginalName(),
            'size' => $file?->getSize(),
            'mime' => $file?->getMimeType(),
        ];
        Log::channel('check_images')->info('Check image upload submitted (app)', $context);

        try {
            $image = $upload->attach($found, $file, 'file');
        } catch (ValidationException $e) {
            Log::channel('check_images')->warning('Check image upload refused (app)', $context + ['errors' => $e->errors()]);

            throw $e;
        } catch (\Throwable $e) {
            Log::channel('check_images')->error('Check image upload failed (app)', $context + ['error' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);

            return response()->json(['message' => 'The check image could not be added: '.$e->getMessage()], 422);
        }

        Log::channel('check_images')->info('Check image upload saved (app)', $context + ['check_image_id' => $image->id, 'image' => $image->image_filename, 'payee' => $image->payee]);

        $found->refresh()->load(['bank_account.bank', 'vendor', 'user']);

        return response()->json(['data' => (new CheckResource($found))->withDetail()->toArray($request)], 201);
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
