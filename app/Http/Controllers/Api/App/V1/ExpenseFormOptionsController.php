<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Expenses\AppExpenseDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The expense create/edit form's "Paid By", "Reimbursement" and "Attach to
 * Existing Check" picker options — mirrors
 * App\Livewire\Expenses\ExpenseCreate's employees()/reimbursment_vendors()/
 * available_checks() computed properties exactly (see
 * App\Services\Expenses\AppExpenseDirectory for the shared query logic).
 */
class ExpenseFormOptionsController extends Controller
{
    public function __invoke(Request $request, AppExpenseDirectory $directory): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'employees' => $directory->employees($user)->map(fn (User $employee) => [
                'id' => $employee->id,
                'name' => $employee->first_name,
            ])->values()->all(),
            'reimbursement_vendors' => $directory->reimbursementVendors()->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->business_name,
            ])->values()->all(),
            'checks' => $directory->checkOptions(),
        ]]);
    }
}
