<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreQuickReceiptRequest;
use App\Http\Resources\Api\App\ExpenseResource;
use App\Services\Expenses\QuickReceiptCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The app's "Upload Receipt" quick-capture button (the web's
 * App\Livewire\Expenses\ExpenseCreate::uploadReceipt(), reached from the
 * Expenses index's "Upload Receipt" action) — see
 * App\Services\Expenses\QuickReceiptCapture for the shared OCR/create
 * logic. Returns the new Expense's full detail shape so the app can open
 * it straight on the Expense edit screen, same as the web opening its edit
 * modal right after the OCR read.
 */
class QuickReceiptController extends Controller
{
    public function store(StoreQuickReceiptRequest $request, QuickReceiptCapture $capture): JsonResponse
    {
        $user = $request->user();
        $belongsToVendorId = $request->validated('belongs_to_vendor_id');

        try {
            $expense = $capture->capture(
                $request->file('file'),
                (bool) $request->validated('is_material_order'),
                $belongsToVendorId !== null ? (int) $belongsToVendorId : null,
                (int) $user->vendor->id,
                (int) $user->id,
            );
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        $expense->load(['vendor', 'project', 'distribution', 'splits.project:id,project_name', 'splits.distribution:id,name', 'receipts', 'checks.bank_account.bank', 'check.bank_account.bank']);

        return response()->json(['data' => (new ExpenseResource($expense))->withDetail()->toArray($request)], 201);
    }
}
