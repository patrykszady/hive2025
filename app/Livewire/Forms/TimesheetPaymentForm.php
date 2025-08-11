<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Vendor;

use Livewire\Form;

class TimesheetPaymentForm extends Form
{
    public $payee_name = '';
    public $first_name = '';
    public $via_vendor_back = null;
    public $date = null;
    public $paid_by = null;
    public $invoice = null;

    /**
     * Define the validation rules exclusively for form-level fields.
     */
    public function rules(): array
    {
        return [
            'payee_name'      => 'nullable',
            'first_name'      => 'nullable',
            'via_vendor_back' => 'nullable',
            'date'            => 'required|date|before_or_equal:today|after:2017-01-01',
            'paid_by' => "required_if:bank_account_id,\"\"",
            'invoice' => 'required_with:paid_by',
        ];
    }

    public function setUser($user)
    {
        $this->payee_name = $user->payee_name;
        $this->first_name = $user->first_name;
        $this->via_vendor_back = $user->via_vendor_back;

        $this->date = today()->format('Y-m-d');
    }

    public function store()
    {
        //complete this on CheckObserver
        if (! is_null($this->component->user->pivot_user_vendor)) {
            $via_vendor = Vendor::findOrFail($this->component->user->pivot_user_vendor);
            if ($via_vendor->registration) {
                if ($via_vendor->registration['registered']) {
                }
            }
        }

        if (isset($via_vendor)) {
            $check_user_id = null;
            $check_vendor_id = $via_vendor->id;
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

        //weekly_timesheets
        foreach ($this->component->weekly_timesheets->where('checkbox', 'true') as $weekly_timesheet) {
            //ignore 'checkbox' attribute when saving
            $weekly_timesheet->offsetUnset('checkbox');
            $weekly_timesheet->check_id = isset($check) ? $check->id : null;
            $weekly_timesheet->paid_by = isset($check) ? null : $this->paid_by;
            $weekly_timesheet->invoice = isset($check) ? null : $this->invoice;
            $weekly_timesheet->save();
        }

        //employee_weekly_timesheets
        //09-05-2023 can we get here if check is not set ? shouldnt... validate if $employee_weekly_timesheets ? addError ..has to be paid by a Check not Paid by.
        foreach ($this->component->employee_weekly_timesheets->where('checkbox', 'true') as $weekly_timesheet) {
            //ignore 'checkbox'
            $weekly_timesheet->offsetUnset('checkbox');
            $weekly_timesheet->check_id = $check->id;
            $weekly_timesheet->save();
        }

        //user_paid_expenses
        foreach ($this->component->user_paid_expenses->where('checkbox', 'true') as $expense) {
            //ignore 'checkbox'
            $expense->offsetUnset('checkbox');
            $expense->check_id = isset($check) ? $check->id : null;
            // $expense->paid_by = isset($check) ? NULL : $this->paid_by;
            $expense->save();
        }

        //user_reimbursement_expenses
        foreach ($this->component->user_reimbursement_expenses->where('checkbox', 'true') as $expense) {
            //ignore 'checkbox'
            $expense->offsetUnset('checkbox');
            $expense->check_id = isset($check) ? $check->id : null;
            $expense->paid_by = isset($check) ? null : $this->paid_by;
            $expense->save();
        }

        //user_paid_by_reimbursements
        // foreach ($this->component->user_paid_by_reimbursements->where('checkbox', 'true') as $expense) {
        //     //ignore 'checkbox'
        //     $expense->offsetUnset('checkbox');
        //     $expense->check_id = isset($check) ? $check->id : null;
        //     // $expense->paid_by = isset($check) ? NULL : $this->paid_by;
        //     $expense->save();
        // }

        //find Check and create_payment_from_check if via_vendor?
        //06-01-2023 should be done in observer
        if (isset($via_vendor)) {
            if ($via_vendor->registration) {
                if ($via_vendor->registration['registered']) {
                    app(\App\Http\Controllers\VendorRegisteredController::class)
                        ->create_payment_from_check(
                            $check,
                            $check->timesheets,
                            $via_vendor
                        );
                }
            }
        }

        if (isset($check)) {
            $expenses = $check->expenses;
            foreach ($expenses as $expense) {
                if ($expense->reimbursment != null && $expense->reimbursment != 'Client') {
                    $expense->amount = substr($expense->amount, 0, 1) == '-' ? $expense->amount : '-'.$expense->amount;
                }
            }

            //$check->expenses->whereNotNull('paid_by')->whereNull('reimbursment')->sum('amount') +
            $check->amount = $check->timesheets->sum('amount') + $expenses->sum('amount');
            $check->save();

            return $check;
        } else {
            return 'timesheets';
        }
    }
}
