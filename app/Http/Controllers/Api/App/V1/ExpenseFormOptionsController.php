<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Checks\CheckNumberSuggester;
use App\Services\Expenses\AppExpenseDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The expense create/edit form's "Paid By", "Reimbursement", "Attach to
 * Existing Check" and "Record a New Check" picker options — mirrors
 * App\Livewire\Expenses\ExpenseCreate's employees()/reimbursment_vendors()/
 * available_checks() computed properties and App\Traits\HandlesChecks'
 * bank_accounts/autoCheckNumber() exactly (see
 * App\Services\Expenses\AppExpenseDirectory for the shared query logic).
 */
class ExpenseFormOptionsController extends Controller
{
    public function __invoke(Request $request, AppExpenseDirectory $directory, CheckNumberSuggester $suggester): JsonResponse
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
            'bank_accounts' => $directory->bankAccounts()->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $account->getNameAndType(),
                'next_check_number' => $suggester->next($account),
            ])->values()->all(),
        ]]);
    }
}
