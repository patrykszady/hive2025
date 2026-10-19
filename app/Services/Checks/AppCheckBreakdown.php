<?php

namespace App\Services\Checks;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;

/**
 * The Hive Contractors app's Check detail screen: what a check paid for —
 * expenses, timesheets, and the deductions settled against it — reshaped
 * from the same underlying records App\Livewire\Checks\CheckShow reads
 * (Check::linkedExpenseIds(), the check's own timesheets), simplified to
 * the three buckets a phone screen needs instead of CheckShow's full
 * seven-card breakdown (vendor reimbursements, distributions, paid-back
 * expenses, … stay web-only; see the "Open in Hive" button on the detail
 * screen).
 */
class AppCheckBreakdown
{
    /**
     * Every expense this check settled, whether it added to the check
     * (is_deduction false) or was deducted from it because the payee owed
     * the company (is_deduction true — see Check::isSettlementDeduction()).
     *
     * @return list<array{id: int, amount: float, is_deduction: bool, date: ?string, vendor: ?array{id: int, name: string}, project: ?array{id: int, name: string}, note: ?string}>
     */
    public function expenses(Check $check): array
    {
        $ids = $check->linkedExpenseIds();

        if ($ids->isEmpty()) {
            return [];
        }

        return Expense::query()
            ->whereIn('id', $ids)
            ->with(['vendor:id,business_name,business_type', 'project:id,project_name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'amount' => (float) $expense->amount,
                'is_deduction' => $check->isSettlementDeduction($expense),
                'date' => $expense->date?->toDateString(),
                'vendor' => $expense->vendor ? ['id' => $expense->vendor->id, 'name' => $expense->vendor->name] : null,
                'project' => $expense->project ? ['id' => $expense->project->id, 'name' => $expense->project->project_name] : null,
                'note' => $expense->note,
            ])
            ->values()
            ->all();
    }

    /**
     * This check's payee's own timesheet rows paid by it (CheckShow's
     * "weekly_timesheets" — employee-paid-timesheets-for-others stays
     * web-only). Nothing when the check has no payee user (a vendor/cash
     * check never pays a timesheet).
     *
     * @return list<array{id: int, date: ?string, hours: ?float, amount: float, project: ?array{id: int, name: string}}>
     */
    public function timesheets(Check $check): array
    {
        if (! $check->user_id) {
            return [];
        }

        return Timesheet::query()
            ->where('check_id', $check->id)
            ->where('user_id', $check->user_id)
            ->with('project:id,project_name')
            ->orderByDesc('date')
            ->get()
            ->map(fn (Timesheet $timesheet) => [
                'id' => $timesheet->id,
                'date' => $timesheet->date?->toDateString(),
                'hours' => $timesheet->hours !== null ? (float) $timesheet->hours : null,
                'amount' => (float) $timesheet->amount,
                'project' => $timesheet->project ? ['id' => $timesheet->project->id, 'name' => $timesheet->project->project_name] : null,
            ])
            ->values()
            ->all();
    }
}
