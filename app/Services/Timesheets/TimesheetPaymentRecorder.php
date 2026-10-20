<?php

namespace App\Services\Timesheets;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pays a team member: settles the selected weekly timesheets, timesheets
 * they're owed as someone else's payer, expenses they paid out of pocket,
 * and reimbursements owed back — either by writing a Check or by marking
 * them paid through another employee (`paid_by`). Extracted from
 * App\Livewire\Forms\TimesheetPaymentForm::store() (which now calls here) so
 * the Hive Contractors app's pay screen
 * (App\Http\Controllers\Api\App\V1\TimesheetPaymentController::store())
 * writes the exact same rows and side effects.
 *
 * Every id in $selection rides in on caller-controlled input — the
 * controller/component is responsible for intersecting it against what
 * TimesheetPaymentOptions::forUser() actually offered $target before calling
 * here (both callers already do this), so this class only ever acts on rows
 * that were genuinely unsettled and genuinely $target's.
 */
class TimesheetPaymentRecorder
{
    /**
     * @param  array{weekly_timesheet_ids: list<int>, employee_weekly_timesheet_ids: list<int>, paid_expense_ids: list<int>, reimbursement_expense_ids: list<int>}  $selection
     *
     * Returns the Check written, or null when the payment settled entirely
     * through `paid_by` (mirrors the web's `return 'timesheets'` sentinel —
     * the caller tells the two apart by whether this is null, not by a
     * string).
     */
    public function record(
        User $actingUser,
        User $target,
        array $selection,
        string $date,
        ?string $checkType,
        ?int $checkNumber,
        ?int $bankAccountId,
        ?int $paidBy,
        ?string $invoice,
    ): ?Check {
        // Paid-for or reimbursement expenses can only settle through a
        // check — same business rule, and the same error copy, as
        // App\Services\Vendors\VendorPaymentRecorder::record()'s own
        // guard. Mirrors the web's own TimesheetPaymentCreate::
        // getDisablePaidByProperty(), which disables the Paid By select
        // under this exact condition (timesheets/payment-form.blade.php's
        // $this->disablePaidBy); this is the server backstop for a client
        // that submits around it.
        if (! empty($paidBy) && (! empty($selection['paid_expense_ids']) || ! empty($selection['reimbursement_expense_ids']))) {
            throw ValidationException::withMessages([
                'paid_by' => 'Reimbursement deductions can only be settled by a check — clear Paid By or unselect the reimbursements.',
            ]);
        }

        $check = null;

        if (empty($paidBy)) {
            $check = Check::create([
                'check_type' => $checkType,
                'check_number' => $checkNumber,
                'date' => $date,
                'bank_account_id' => $bankAccountId,
                'user_id' => $target->id,
                'vendor_id' => null,
                'belongs_to_vendor_id' => $actingUser->vendor->id,
                'created_by_user_id' => $actingUser->id,
            ]);
        }

        Timesheet::where('user_id', $target->id)
            ->whereNull('check_id')->whereNull('paid_by')->whereNull('deleted_at')
            ->whereIn('id', $selection['weekly_timesheet_ids'])
            ->get()
            ->each(function (Timesheet $timesheet) use ($check, $paidBy, $invoice): void {
                $timesheet->check_id = $check?->id;
                $timesheet->paid_by = $check ? null : $paidBy;
                $timesheet->invoice = $check ? null : $invoice;
                $timesheet->save();
            });

        if ($check) {
            Timesheet::where('paid_by', $target->id)
                ->whereNull('check_id')->whereNull('deleted_at')
                ->whereIn('id', $selection['employee_weekly_timesheet_ids'])
                ->update(['check_id' => $check->id]);
        }

        Expense::where('paid_by', $target->id)
            ->where(fn ($q) => $q->whereNull('reimbursment')->orWhere('reimbursment', 'Client'))
            ->whereNull('check_id')
            ->whereIn('id', $selection['paid_expense_ids'])
            ->get()
            ->each(function (Expense $expense) use ($check): void {
                $expense->check_id = $check?->id;
                $expense->save();
            });

        Expense::where('reimbursment', (string) $target->id)
            ->whereNull('paid_by')->whereNull('check_id')
            ->whereIn('id', $selection['reimbursement_expense_ids'])
            ->get()
            ->each(function (Expense $expense) use ($check, $paidBy): void {
                $expense->check_id = $check?->id;
                $expense->paid_by = $check ? null : $paidBy;
                $expense->save();
            });

        if ($check) {
            // Reimbursement expenses count as deductions (centralized in
            // Check::recalculateAmount).
            $check->recalculateAmount();
        }

        return $check;
    }
}
