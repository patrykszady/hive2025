<?php

namespace App\Services\Timesheets;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Checks\CheckNumberSuggester;
use Illuminate\Support\Collection;

/**
 * The pay-a-team-member form's data: what's owed to $user and how it may be
 * paid. Extracted from App\Livewire\Timesheets\TimesheetPaymentCreate::mount()
 * (which now reads from here) so the Hive Contractors app's pay screen
 * (App\Http\Controllers\Api\App\V1\TimesheetPaymentController::show()) shows
 * exactly the same unsettled rows, in the same default selection, as the web
 * page it mirrors.
 */
class TimesheetPaymentOptions
{
    /**
     * @return array{
     *     payee_name: string,
     *     weekly_timesheets: Collection<int, Timesheet>,
     *     employee_weekly_timesheets: Collection<int, Timesheet>,
     *     paid_expenses: Collection<int, Expense>,
     *     reimbursement_expenses: Collection<int, Expense>,
     *     employees: Collection<int, User>,
     *     bank_accounts: Collection<int, BankAccount>,
     * }
     */
    public function forUser(User $actingUser, User $target): array
    {
        $viaVendor = $target->via_vendor;

        return [
            'payee_name' => $viaVendor ? trim($viaVendor->business_name) : $target->full_name,
            // Default selection (web: selectedWeeklyTimesheets defaults every
            // id to true) — every row here starts selected.
            'weekly_timesheets' => Timesheet::where('user_id', $target->id)
                ->whereNull('check_id')->whereNull('paid_by')->whereNull('deleted_at')
                ->orderByDesc('date')->get(),
            // Default selection (web: selectedEmployeeWeeklyTimesheets
            // defaults every id to true) — also starts selected.
            'employee_weekly_timesheets' => Timesheet::with('user')
                ->where('paid_by', $target->id)->whereNull('check_id')->whereNull('deleted_at')
                ->orderByDesc('date')->get(),
            // Default selection (web: selectedUserPaidExpenses defaults
            // every id to FALSE) — starts unselected.
            'paid_expenses' => Expense::where('paid_by', $target->id)
                ->where(fn ($q) => $q->whereNull('reimbursment')->orWhere('reimbursment', 'Client'))
                ->whereNull('check_id')
                ->orderByDesc('date')->get(),
            // Default selection (web: selectedUserReimbursementExpenses
            // defaults every id to FALSE) — starts unselected.
            'reimbursement_expenses' => Expense::where('reimbursment', (string) $target->id)
                ->whereNull('paid_by')->whereNull('check_id')
                ->orderByDesc('date')->get(),
            'employees' => $actingUser->vendor->users()->employed()->whereKeyNot($target->getKey())->get(),
            'bank_accounts' => BankAccount::latestCheckingAccounts()->get(),
        ];
    }

    /**
     * The payment total for exactly the rows named in $selection — mirrors
     * TimesheetPaymentCreate::getWeeklyTimesheetsTotalProperty() exactly
     * (never the Index's payable() preview, which subtracts a fifth term
     * this page's own total does not — see AppPendingBalance's docblock).
     * Recomputed from the database here rather than trusting a client-sent
     * total, so a tampered total can never inflate or hide what a check
     * actually pays.
     *
     * @param  array{weekly_timesheet_ids: list<int>, employee_weekly_timesheet_ids: list<int>, paid_expense_ids: list<int>, reimbursement_expense_ids: list<int>}  $selection
     */
    public function totalForSelection(User $target, array $selection): float
    {
        $total = Timesheet::where('user_id', $target->id)
            ->whereNull('check_id')->whereNull('paid_by')->whereNull('deleted_at')
            ->whereIn('id', $selection['weekly_timesheet_ids'])
            ->sum('amount');

        $total += Timesheet::where('paid_by', $target->id)
            ->whereNull('check_id')->whereNull('deleted_at')
            ->whereIn('id', $selection['employee_weekly_timesheet_ids'])
            ->sum('amount');

        $total += Expense::where('paid_by', $target->id)
            ->where(fn ($q) => $q->whereNull('reimbursment')->orWhere('reimbursment', 'Client'))
            ->whereNull('check_id')
            ->whereIn('id', $selection['paid_expense_ids'])
            ->sum('amount');

        $total -= Expense::where('reimbursment', (string) $target->id)
            ->whereNull('paid_by')->whereNull('check_id')
            ->whereIn('id', $selection['reimbursement_expense_ids'])
            ->sum('amount');

        return (float) $total;
    }

    /** @see CheckNumberSuggester::next() */
    public function nextCheckNumber(BankAccount $bankAccount): ?int
    {
        return app(CheckNumberSuggester::class)->next($bankAccount);
    }
}
