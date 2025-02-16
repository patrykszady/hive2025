<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Form;

class CheckForm extends Form
{
    use AuthorizesRequests;

    public ?Check $check;

    #[Validate('nullable|date|before_or_equal:today|after:2017-01-01')]
    public $date = null;

    // required_without:check.bank_account_id
    // #[Rule('nullable', as: 'paid by')]
    // public $paid_by = NULL;

    // required_without:check.paid_by
    #[Validate('required', as: 'bank account')]
    public $bank_account_id = null;

    // required_with:check.bank_account_id
    #[Validate('required_with:bank_account_id')]
    public $check_type = null;

    // #[Validate('required_if:check_type,Check')]
    public $check_number = null;

    // required_with:check.paid_by
    // #[Rule('nullable')]
    // public $invoice = NULL;
    public $transaction = false;
    // protected $messages =
    // [
    //     'check.check_number' => 'Check Number is required if Payment Type is Check',
    // ];

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
                })
                    ->ignore($this->check),
            ],
        ];
    }

    public function setCheck(Check $check)
    {
        $this->check = $check;

        $this->bank_account_id = $this->check->bank_account_id;
        $this->check_type = $this->check->check_type;
        $this->check_number = $this->check->check_number;

        if ($this->check->transactions->sum('amount') == $this->check->amount) {
            $this->transaction = true;
        }
    }

    public function update()
    {
        // dd($this);
        // $this->authorize('create', Check::class);
        $this->validate();

        $this->check->update([
            'bank_account_id' => $this->bank_account_id,
            'check_type' => $this->check_type,
            'check_number' => $this->check_number,
        ]);

        return $this->check;
    }

    public function delete()
    {
        $this->check->delete();
    }

    public function store()
    {
        // $this->validate();
        // dd($this);
        dd('in store checkForm');
        // $this->authorize('create', Expense::class);
        // $this->validate();

        //return $check
        // //only
        // ExpenseSplit::create($this->all());

        // $this->reset();
    }
}
