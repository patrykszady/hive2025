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

        // Projects are stored on the component as a plain array (not a Collection of models)
        // Iterate over visible projects that have a positive amount.
        foreach ($this->component->projects as $project) {
            if (!($project['show'] ?? false)) { continue; }
            $amount = $project['amount'] ?? null;
            if (!is_numeric($amount) || $amount <= 0) { continue; }

            Expense::create([
                'amount' => $amount,
                'date' => $this->date,
                // If paying by check (no paid_by value) invoice should be null, otherwise include invoice
                'invoice' => isset($check) ? null : $this->invoice,
                'project_id' => $project['id'],
                'vendor_id' => $this->component->vendor->id,
                'check_id' => $check?->id,
                'paid_by' => $check?->id ? null : $this->paid_by,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'created_by_user_id' => auth()->user()->id,
            ]);
        }

        //09-06-2023 put in observer?
        //if $this->vendor->id is registered
        //create payment for each check (/ payments / expenses / paid_by employee)?
        // if ($this->component->vendor->registration?->registered) {
        //     app(\App\Http\Controllers\VendorRegisteredController::class)
        //         ->create_payment_from_check(
        //             $check,
        //             $check->expenses,
        //             $this->component->vendor
        //         );
        // }

        return $check;
    }
}
