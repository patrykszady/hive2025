<?php

namespace App\Services\Vendors;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Checks\CheckNumberSuggester;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The pay-a-vendor form's data: which of the company's projects owe this
 * vendor a balance, and how the payment may be made. Extracted from
 * App\Livewire\Vendors\VendorPaymentCreate::mount() (which now reads from
 * here) so the Hive Contractors app's pay screen
 * (App\Http\Controllers\Api\App\V1\VendorPaymentController::show()) offers
 * exactly the same projects, in the same default selection, as the web page
 * it mirrors.
 */
class VendorPaymentOptions
{
    /**
     * Every project eligible for a payment to $vendor — mirrors
     * VendorPaymentCreate::mount()'s $projects array exactly: Active,
     * Complete or Service Call, created within the last 2 years, each
     * carrying its bid/expense sums and whether it has an outstanding
     * balance (the web's "show"/"disabled" default). Complete and Service
     * Call projects are still offered even at a $0 balance — the web's own
     * "Add project" picker (availableProjects()) lets an admin add one of
     * these back in by hand, so this returns the whole eligible set rather
     * than filtering down to only the pre-populated ones, exactly as the
     * web component's one upfront query already does.
     *
     * @return list<array{id: int, name: string, address: ?string, balance: float, vendor_expenses_sum: float, vendor_bids_sum: float, has_outstanding_balance: bool}>
     */
    public function projectsFor(Vendor $vendor): array
    {
        $projects = Project::where('created_at', '>', Carbon::now()->subYears(2)->format('Y-m-d'))
            ->status([6, 7, 8]) // Active, Complete, Service Call
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.status_code', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ]);

        return $projects->map(function (Project $project) use ($vendor): array {
            $expensesSum = (float) $project->expenses()->where('vendor_id', $vendor->id)->sum('amount');
            $bidsSum = (float) $project->bids()->vendorBids($vendor->id)->sum('amount');
            $balance = $bidsSum - $expensesSum;

            return [
                'id' => $project->id,
                'name' => $project->project_name,
                'address' => $project->address,
                'balance' => $balance,
                'vendor_expenses_sum' => $expensesSum,
                'vendor_bids_sum' => $bidsSum,
                'has_outstanding_balance' => $balance > 0,
            ];
        })->values()->all();
    }

    /**
     * Unsettled expenses the company paid on $vendor's behalf — mirrors
     * VendorPaymentCreate::mount()'s $vendor_reimbursement_expenses
     * exactly. All default UNSELECTED, same as the web.
     *
     * @return Collection<int, Expense>
     */
    public function reimbursementExpensesFor(Vendor $vendor): Collection
    {
        return Expense::where('reimbursment', 'V:'.$vendor->id)
            ->whereNull('paid_by')->whereNull('check_id')
            ->orderByDesc('date')->get();
    }

    /** @return Collection<int, User> */
    public function employeesFor(User $actingUser): Collection
    {
        return $actingUser->vendor->users()->employed()->get();
    }

    public function bankAccounts(): Collection
    {
        return BankAccount::latestCheckingAccounts()->get();
    }

    /** @see CheckNumberSuggester::next() */
    public function nextCheckNumber(BankAccount $bankAccount): ?int
    {
        return app(CheckNumberSuggester::class)->next($bankAccount);
    }
}
