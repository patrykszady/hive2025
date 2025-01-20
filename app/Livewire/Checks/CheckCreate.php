<?php

namespace App\Livewire\Checks;

use App\Livewire\Forms\CheckForm;
use App\Models\BankAccount;
use App\Models\Check;
use Livewire\Component;

class CheckCreate extends Component
{
    public CheckForm $form;

    // public $expense_update = NULL;

    public $bank_accounts = [];

    public $employees = [];

    public Check $check;

    public $view_text = [
        'card_title' => 'Edit Check',
        'button_text' => 'Update',
        'form_submit' => 'edit',
    ];
    // public $payment_type = NULL;

    protected $listeners = ['validateCheck', 'editCheck'];

    public function mount()
    {
        $this->bank_accounts = BankAccount::with('bank')->where('type', 'Checking')
            ->whereHas('bank', function ($query) {
                return $query->whereNotNull('plaid_access_token');
            })->get();

        $this->employees = auth()->user()->vendor->users()->where('is_employed', 1)->whereNot('users.id', auth()->user()->id)->get();
    }

    public function updated($field)
    {
        // if($field = 'form.bank_account_id'){
        //     $this->form->check_type = NULL;
        //     $this->form->check_number = NULL;
        // }

        if ($field == 'form.check_type') {
            $this->form->check_number = null;
            // if($this->check->check_type == 'Check'){

            // }else{
            //     $this->check->check_number = NULL;
            // }
        }

        // $this->validate();
        $this->validateOnly($field);
    }

    public function validateCheck()
    {
        dd('in validateCheck');
    }

    public function editCheck(Check $check)
    {
        $this->check = $check;
        $this->form->setCheck($check);

        $this->modal('check_form_modal')->show();
    }

    public function edit()
    {
        $check = $this->form->update();
        $this->dispatch('notify',
            type: 'success',
            content: 'Check Updated',
            route: 'checks/'.$check->id
        );

        $this->dispatch('refreshComponent')->to('checks.check-show');
    }

    public function remove()
    {
        $this->form->delete();
        $this->redirect(ChecksIndex::class);
    }

    public function store()
    {
        dd('in store Check');
        $this->validate();
        $this->form->store();

        if ($this->payment_type->getTable() == 'vendors') {
            // dd($this->payment_type->getTable());
            // dd('vendors table');
            //send to VendorPaymentForm
            // dd($this->check);
            $this->dispatch('vendorHasCheck', $this->check);
        } elseif ($this->payment_type->getTable() == 'expenses') {
            $this->dispatch('hasCheck', $this->check);
        }
    }

    public function render()
    {
        //where Active on Bank
        // $this->bank_accounts = BankAccount::with('bank')->where('type', 'Checking')
        // ->whereHas('bank', function ($query) {
        //     return $query->whereNotNull('plaid_access_token');
        // })->get();

        // if(isset($this->check)){
        //     $this->view_text = [
        //         // 'card_title' => 'Update user',
        //         'button_text' => 'Update Payment',
        //         'form_submit' => 'store',
        //     ];

        // }else{
        //     $this->check = Check::make();

        //     $this->view_text = [
        //         // 'card_title' => 'Update user',
        //         'button_text' => 'Save Payment',
        //         'form_submit' => 'store',
        //     ];
        // }

        // $employees = $this->user->vendor->users()->where('is_employed', 1)->whereNot('users.id', $this->user->id)->get();

        // 10-02-2024 CHANGE NEW_FORM ASAP
        return view('livewire.checks.new_form', [
            // 'bank_accounts' => $bank_accounts,
            // 'employees' => $employees,
        ]);
    }
}
