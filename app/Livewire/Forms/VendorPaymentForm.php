<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Expense;
use Illuminate\Validation\Rule;
use Livewire\Form;

class VendorPaymentForm extends Form
{
    public $date = null;
    public $paid_by = null;
    public $invoice = null;

    public function rules(): array
    {
        return [
            'date'    => 'required|date|before_or_equal:today|after:2017-01-01',
            'paid_by' => "required_if:bank_account_id,\"\"",
            'invoice' => 'required_with:paid_by',
        ];
    }

    // protected $messages =
    // [
    //     'payment_projects.*.amount.required' => 'Project Amount is required if included. "Remove Project" if not included in this Payment',
    //     'payment_projects.*.amount.numeric' => 'Project Amount must be a number if included.',
    //     'payment_projects.*.amount.min' => 'Project Amount must be at least $0.01 if included.',
    //     'payment_projects.*.amount.regex' => 'Amount format is incorrect. Format is 2145.36. No commas and only two digits after decimal allowed. If amount is under $1.00, use 0.XX',
    //     'check.check_number.required_if' => 'Check Number is required if Payment Type is Check',
    //     'check.check_number.unique' => 'Check Number is already taken.',
    // ];

    public function store()
    {
        //create expense for each $payment_projects. create one Check for all Expenses and associate with the Check.
        if (empty($this->paid_by)) {
            $check = Check::create([
                'check_type' => $this->component->check_type,
                'check_number' => $this->component->check_number,
                'date' => $this->date,
                'bank_account_id' => $this->component->bank_account_id,
                'vendor_id' => $this->component->vendor->id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'created_by_user_id' => auth()->user()->id,
            ]);
        } else {
            $check = null;
        }

        foreach ($this->component->projects->where('show', 'true')->where('amount', '>', 0) as $project) {
            //ignore 'show' attribute when saving
            $project->offsetUnset('show');
            Expense::create([
                'amount' => $project->amount,
                'date' => $this->date,
                'invoice' => $this->invoice,
                'project_id' => $project->id,
                'vendor_id' => $this->component->vendor->id,
                'check_id' => isset($check) ? $check->id : null,
                'paid_by' => isset($check) ? null : $this->paid_by,
                'invoice' => isset($check) ? null : $this->invoice,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'created_by_user_id' => auth()->user()->id,
            ]);
        }

        //09-06-2023 put in observer?
        //if $this->vendor->id is registered
        //create payment for each check (/ payments / expenses / paid_by employee)?
        if ($this->component->vendor->registration['registered']) {
            app(\App\Http\Controllers\VendorRegisteredController::class)
                ->create_payment_from_check(
                    $check,
                    $check->expenses,
                    $this->component->vendor
                );
        }

        return $check;
    }
}
