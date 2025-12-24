<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Vendor;

use Livewire\Form;

class TimesheetPaymentForm extends Form
{
    public $first_name = '';
    public $date = null;
    public $paid_by = null;
    public $invoice = null;

    /**
     * Define the validation rules exclusively for form-level fields.
     */
    public function rules(): array
    {
        return [
            'first_name'      => 'nullable',
            'date'            => 'required|date|before_or_equal:today|after:2017-01-01',
            'paid_by' => "required_if:bank_account_id,\"\"",
            'invoice' => 'required_with:paid_by',
        ];
    }

    public function setUser($user)
    {
        $this->first_name = $user->first_name;
        // Date will be set by browser's local date via Alpine.js
    }

    public function store()
    {
        // Use resolved viaVendor from parent component (if present) instead of dynamic pivot property
        $via_vendor = $this->component->viaVendor; // may be null

        // Previous behavior: when a user was payable "via" a vendor we created the check with vendor_id only
        // (user_id null). This caused checks (e.g. 3521 / 3522) to lack the direct employee user_id reference.
        // Updated behavior: always attribute the check to the user (user_id) so downstream logic (timesheets,
        // ownership, filtering) consistently finds the employee. We intentionally leave vendor_id null here to
        // preserve the invariant that a payment check is either to a user OR a vendor, not both, unless we later
        // introduce an explicit via_vendor_id column.
        if ($via_vendor) {
            $check_user_id = $this->component->user->id;    // ensure employee captured
            $check_vendor_id = null;                        // do not set intermediary vendor on the check
        } else {
            $check_user_id = $this->component->user->id;
            $check_vendor_id = null;
        }

        if (empty($this->paid_by)) {
            $check = Check::create([
                'check_type' => $this->component->check_type,
                'check_number' => $this->component->check_number,
                'date' => $this->date,
                'bank_account_id' => $this->component->bank_account_id,
                'user_id' => $check_user_id,
                'vendor_id' => $check_vendor_id,
                //via_vendor_id....
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'created_by_user_id' => auth()->user()->id,
            ]);
        }

        // Helper closures to test selection arrays (safe default false)
        $isSelectedTimesheet = fn($id) => (bool) ($this->component->selectedWeeklyTimesheets[$id] ?? false);
        $isSelectedEmployeeTimesheet = fn($id) => (bool) ($this->component->selectedEmployeeWeeklyTimesheets[$id] ?? false);
        $isSelectedPaidExpense = fn($id) => (bool) ($this->component->selectedUserPaidExpenses[$id] ?? false);
        $isSelectedReimbExpense = fn($id) => (bool) ($this->component->selectedUserReimbursementExpenses[$id] ?? false);

        // Weekly timesheets (user's own)
        foreach ($this->component->weekly_timesheets as $weekly_timesheet) {
            if (! $isSelectedTimesheet($weekly_timesheet->id)) { continue; }
            $weekly_timesheet->check_id = isset($check) ? $check->id : null;
            $weekly_timesheet->paid_by = isset($check) ? null : $this->paid_by;
            $weekly_timesheet->invoice = isset($check) ? null : $this->invoice;
            $weekly_timesheet->save();
        }

        // Employee timesheets paid by user (must always attach to created check when paying)
        if (isset($check)) {
            foreach ($this->component->employee_weekly_timesheets as $weekly_timesheet) {
                if (! $isSelectedEmployeeTimesheet($weekly_timesheet->id)) { continue; }
                $weekly_timesheet->check_id = $check->id;
                $weekly_timesheet->save();
            }
        }

        // User paid expenses
        foreach ($this->component->user_paid_expenses as $expense) {
            if (! $isSelectedPaidExpense($expense->id)) { continue; }
            $expense->check_id = isset($check) ? $check->id : null;
            $expense->save();
        }

        // User reimbursement expenses (deductions)
        foreach ($this->component->user_reimbursement_expenses as $expense) {
            if (! $isSelectedReimbExpense($expense->id)) { continue; }
            $expense->check_id = isset($check) ? $check->id : null;
            $expense->paid_by = isset($check) ? null : $this->paid_by;
            $expense->save();
        }

        if (isset($check)) {
            $expenses = $check->expenses; // reload attached expenses (after loops)
            foreach ($expenses as $expense) {
                if ($expense->reimbursment != null && $expense->reimbursment != 'Client') {
                    // Ensure reimbursements reflect as negative for total if not already
                    $expense->amount = str_starts_with((string) $expense->amount, '-') ? $expense->amount : '-'.$expense->amount;
                }
            }
            // Recalculate check amount from attached relations
            $check->amount = $check->timesheets->sum('amount') + $expenses->sum('amount');
            $check->save();
            return $check;
        }

        return 'timesheets';
    }
}
