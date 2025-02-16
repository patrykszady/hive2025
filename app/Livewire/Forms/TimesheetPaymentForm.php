<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Vendor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Form;

class TimesheetPaymentForm extends Form
{
    #[Validate('required')]
    public $payee_name = '';

    #[Validate('required')]
    public $first_name = '';

    #[Validate('required')]
    public $via_vendor_back = null;

    #[Validate('required|date|before_or_equal:today|after:2017-01-01')]
    public $date = null;

    //required_without:check_form.bank_account_id'
    #[Validate('required_without:bank_account_id')]
    public $paid_by = null;

    // required_without:check.paid_by
    #[Validate('required_without:paid_by', as: 'bank account')]
    public $bank_account_id = null;

    // required_with:check.bank_account_id
    #[Validate('required_with:bank_account_id', as: 'type')]
    public $check_type = null;

    // required_if:check.check_type,Check
    // #[Validate('required_if:check_type,Check')]
    public $check_number = null;

    #[Validate('required_with:invoice')]
    public $invoice = null;

    public function rules()
    {
        return [
            'check_number' => [
                'required_if:check_type,Check',
                'nullable',
                'numeric',

                //ignore if vendor_id of Check is same as request()->vendor_id
                // ->ignore($this->check),
                Rule::unique('checks', 'check_number')->where(function ($query) {
                    //->where('vendor_id', '!=', $this->expense->vendor_id)

                    //where per vendor bank_account ... all bank accounts that have the inst ID
                    return $query->where('deleted_at', null)->where('bank_account_id', $this->bank_account_id);
                }),
                // ->ignore($this->check),
            ],
        ];
    }
    // protected function rules()
    // {
    //     return [
    //         'user.full_name' => 'nullable',
    //         'user.payee_name' => 'nullable',
    //         'user.via_vendor_back' => 'nullable',

    //         'check.date' => 'required|date|before_or_equal:today|after:2017-01-01',
    //         'check.paid_by' => 'required_without:check.bank_account_id',

    //         'check.bank_account_id' => 'required_without:check.paid_by',
    //         'check.check_type' => 'required_with:check.bank_account_id',
    //          //02-21-2023 - used in MILTIPLE of places... VendorPaymentForm...
    //         'check.check_number' => [
    //             //ignore if vendor_id of Check is same as request()->vendor_id
    //             'required_if:check.check_type,Check',
    //             'nullable',
    //             'numeric',
    //             Rule::unique('checks', 'check_number')->where(function ($query) {
    //                 return $query->where('deleted_at', NULL)->where('bank_account_id', $this->check->bank_account_id);
    //             }),
    //             //->ignore(request()->get('check_id_id'))
    //         ],
    //         'check.invoice' => 'required_with:check.paid_by',

    //         //7/18/2022 ignore if updating Check  ->ignore(request()->get('check_id_id'))
    //         // 'check.check_number' => [
    //         //     'required_if:check.check_type,Check',
    //         //     'nullable',
    //         //     Rule::unique('checks', 'check_number')->where(function ($query) {
    //         //         return $query->whereNull('deleted_at')->where('bank_account_id', $this->check->bank_account_id);
    //         //     }),
    //         //     'nullable',
    //         //     'numeric',
    //         // ],
    //     ];
    // }

    public function setUser($user)
    {
        $this->payee_name = $user->payee_name;
        $this->first_name = $user->first_name;
        $this->via_vendor_back = $user->via_vendor_back;

        $this->date = today()->format('Y-m-d');
    }

    public function store()
    {
        $this->validate();

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
                'check_type' => $this->check_type,
                'check_number' => $this->check_number,
                'date' => $this->date,
                'bank_account_id' => $this->bank_account_id,
                'user_id' => $check_user_id,
                'vendor_id' => $check_vendor_id,
                //via_vendor_id....
                'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
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
        foreach ($this->component->user_paid_by_reimbursements->where('checkbox', 'true') as $expense) {
            //ignore 'checkbox'
            $expense->offsetUnset('checkbox');
            $expense->check_id = isset($check) ? $check->id : null;
            // $expense->paid_by = isset($check) ? NULL : $this->paid_by;
            $expense->save();
        }

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

        // dd($this->component->weekly_timesheets->where('checkbox', 'true')->sum('amount'));
        // dd($this->component->user_reimbursement_expenses->sum('amount'));

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
