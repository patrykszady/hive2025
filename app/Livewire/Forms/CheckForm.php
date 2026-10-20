<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Services\Checks\CheckUpdateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Form;

class CheckForm extends Form
{
    use AuthorizesRequests;

    public ?Check $check;

    /** Validated in rules(): the cleared-date ceiling depends on the check. */
    public $date = null;

    // required_without:check.bank_account_id
    // #[Rule('nullable', as: 'paid by')]
    // public $paid_by = NULL;

    // required_without:check.paid_by
    #[Validate(as: 'bank account')]
    public $bank_account_id = null;

    // required_with:check.bank_account_id
    #[Validate('required_with:bank_account_id')]
    public $check_type = null;

    // #[Validate('required_if:check_type,Check')]
    public $check_number = null;

    public $vendor_id = null;

    public $user_id = null;

    public $transaction = false;
    // protected $messages =
    // [
    //     'check.check_number' => 'Check Number is required if Payment Type is Check',
    // ];

    public function rules()
    {
        $vendorId = auth()->user()->vendor->id;

        // Not editing yet (the create path is dead code — see CheckCreate's
        // docblock): skip the check-specific rules CheckUpdateService needs
        // a $check for.
        if (! isset($this->check)) {
            return [
                'bank_account_id' => ['required', \Illuminate\Validation\Rule::exists('bank_accounts', 'id')->where('vendor_id', $vendorId)],
                'date' => ['nullable', 'date', 'after:2017-01-01'],
            ];
        }

        return app(CheckUpdateService::class)->rules($this->check, $vendorId, $this->bank_account_id);
    }

    public function setCheck(Check $check)
    {
        $this->check = $check;

        $this->bank_account_id = $this->check->bank_account_id;
        $this->check_type = $this->check->check_type;
        $this->check_number = $this->check->check_number;
        $this->vendor_id = $this->check->vendor_id;
        $this->user_id = $this->check->user_id;
        $this->date = $this->check->date?->format('Y-m-d');

        if ($this->check->transactions->sum('amount') == $this->check->amount) {
            $this->transaction = true;
        }
    }

    public function update()
    {
        $this->authorize('update', $this->check);
        $this->validate();

        return app(CheckUpdateService::class)->update($this->check, [
            'bank_account_id' => $this->bank_account_id,
            'check_type' => $this->check_type,
            'check_number' => $this->check_number,
            'vendor_id' => $this->vendor_id,
            'user_id' => $this->user_id,
            'date' => $this->date,
        ]);
    }

    /** The latest date the check may carry: the day the bank cleared it, when it has. */
    protected function latestAllowedDate(): ?string
    {
        if (! isset($this->check)) {
            return null;
        }

        return app(CheckUpdateService::class)->latestAllowedDate($this->check);
    }

    public function delete()
    {
        $this->authorize('delete', $this->check);
        app(CheckUpdateService::class)->remove($this->check);
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
