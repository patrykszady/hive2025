<?php

namespace App\Services\Timesheets;

use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;

/**
 * What a team member is currently owed — read-only mirrors of the figures
 * the web's payroll screens compute, reshaped for the app's "my balance"
 * view (TimesheetPaymentCreate's own-page math) and the admin "who do I
 * owe" list (TimesheetPaymentIndex's preview totals). Deliberately NOT a
 * shared service with those two Livewire components: they write checks and
 * touch real payroll, so this app track keeps its own read path rather than
 * refactor tested-in-production money code under time pressure — see the
 * hive-app-wt-field build notes. Paying a balance stays entirely web-only.
 */
class AppPendingBalance
{
    /**
     * $user's own outstanding balance — the exact four components and total
     * TimesheetPaymentCreate::mount()/getWeeklyTimesheetsTotalProperty()
     * compute when $user is viewing their own page (viaVendor's "paid
     * through an intermediary" cosmetic naming doesn't change these sums).
     *
     * @return array{total: float, weekly_timesheets: list<array<string, mixed>>, employee_weekly_timesheets: list<array<string, mixed>>, paid_expenses: list<array<string, mixed>>, reimbursement_expenses: list<array<string, mixed>>}
     */
    public function ownBalance(User $user): array
    {
        // withoutGlobalScopes(): TimesheetScope/ExpenseScope's Member branch
        // narrows to "where user_id/paid_by = me", which would hide this
        // exact person's OWN reimbursement-owed-to-them and paid-through-me
        // rows (their paid_by/reimbursment is someone ELSE's id by design).
        // Every query below already hard-filters to this one person's own
        // identifiers, so bypassing the scope here reads nothing it
        // shouldn't — it just stops that scope (built for the general
        // Expenses/Timesheets lists) from hobbling this one person's own
        // balance, which TimesheetPolicy::viewPayment already grants them.
        $weeklyTimesheets = Timesheet::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNull('check_id')->whereNull('paid_by')->whereNull('deleted_at')
            ->orderByDesc('date')->get();

        $employeeWeeklyTimesheets = Timesheet::withoutGlobalScopes()->with('user')
            ->where('paid_by', $user->id)->whereNull('check_id')->whereNull('deleted_at')
            ->orderByDesc('date')->get();

        $paidExpenses = Expense::withoutGlobalScopes()
            ->where('paid_by', $user->id)
            ->where(fn ($q) => $q->whereNull('reimbursment')->orWhere('reimbursment', 'Client'))
            ->whereNull('check_id')
            ->orderByDesc('date')->get();

        $reimbursementExpenses = Expense::withoutGlobalScopes()
            ->where('reimbursment', (string) $user->id)
            ->whereNull('paid_by')->whereNull('check_id')
            ->orderByDesc('date')->get();

        $total = $weeklyTimesheets->sum('amount')
            + $employeeWeeklyTimesheets->sum('amount')
            + $paidExpenses->sum('amount')
            - $reimbursementExpenses->sum('amount');

        return [
            'total' => (float) $total,
            'weekly_timesheets' => $this->timesheetRows($weeklyTimesheets),
            'employee_weekly_timesheets' => $this->timesheetRows($employeeWeeklyTimesheets, withPayer: true),
            'paid_expenses' => $this->expenseRows($paidExpenses),
            'reimbursement_expenses' => $this->expenseRows($reimbursementExpenses),
        ];
    }

    /**
     * Every employed team member's outstanding total — mirrors
     * TimesheetPaymentIndex's per-user preview total exactly, including its
     * fifth deduction (expenses the person paid that were themselves someone
     * else's reimbursement) that TimesheetPaymentCreate's own-page total
     * above does not subtract — a pre-existing difference between the two
     * web screens, kept as-is rather than "fixed" here.
     *
     * @return list<array{user: array{id: int, name: string}, total: float}>
     */
    public function payable(User $admin): array
    {
        $employees = $admin->vendor->users()->where('is_employed', 1)->get();
        $employeeIds = $employees->pluck('id')->all();

        $timesheetsByUser = Timesheet::whereIn('user_id', $employeeIds)
            ->whereNull('check_id')->whereNull('paid_by')->whereNull('deleted_at')
            ->get()->groupBy('user_id');

        $timesheetsByPayer = Timesheet::whereIn('paid_by', $employeeIds)
            ->whereNull('check_id')->whereNull('deleted_at')
            ->get()->groupBy('paid_by');

        $paidExpensesByPayer = Expense::whereIn('paid_by', $employeeIds)
            ->where(fn ($q) => $q->whereNull('reimbursment')->orWhere('reimbursment', 'Client'))
            ->whereNull('check_id')
            ->get()->groupBy('paid_by');

        $reimbursementsByUser = Expense::whereIn('reimbursment', array_map('strval', $employeeIds))
            ->whereNull('paid_by')->whereNull('check_id')
            ->get()->groupBy('reimbursment');

        $paidByReimbursementsByPayer = Expense::whereIn('paid_by', $employeeIds)
            ->whereNotIn('reimbursment', ['', 'Client'])
            ->whereNull('check_id')
            ->get()->groupBy('paid_by');

        return $employees->map(function (User $employee) use (
            $timesheetsByUser, $timesheetsByPayer, $paidExpensesByPayer, $reimbursementsByUser, $paidByReimbursementsByPayer
        ): array {
            $total = ($timesheetsByUser->get($employee->id) ?? collect())->sum('amount')
                + ($timesheetsByPayer->get($employee->id) ?? collect())->sum('amount')
                + ($paidExpensesByPayer->get($employee->id) ?? collect())->sum('amount')
                - ($reimbursementsByUser->get($employee->id) ?? $reimbursementsByUser->get((string) $employee->id) ?? collect())->sum('amount')
                - ($paidByReimbursementsByPayer->get($employee->id) ?? collect())->sum('amount');

            return [
                'user' => ['id' => $employee->id, 'name' => trim($employee->first_name.' '.$employee->last_name)],
                'total' => (float) $total,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function timesheetRows(\Illuminate\Support\Collection $timesheets, bool $withPayer = false): array
    {
        return $timesheets->map(fn (Timesheet $timesheet): array => [
            'id' => $timesheet->id,
            'date' => $timesheet->date->format('Y-m-d'),
            'amount' => (float) $timesheet->amount,
            'hours' => (float) $timesheet->hours,
            'payer' => $withPayer ? trim(($timesheet->user->first_name ?? '').' '.($timesheet->user->last_name ?? '')) : null,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function expenseRows(\Illuminate\Support\Collection $expenses): array
    {
        return $expenses->map(fn (Expense $expense): array => [
            'id' => $expense->id,
            'date' => $expense->date?->format('Y-m-d'),
            'amount' => (float) $expense->amount,
            'note' => $expense->note,
        ])->values()->all();
    }
}
