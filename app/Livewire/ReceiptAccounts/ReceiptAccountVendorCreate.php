<?php

namespace App\Livewire\ReceiptAccounts;

use App\Models\Distribution;
use App\Models\ReceiptAccount;
use App\Models\Vendor;
use Livewire\Component;

class ReceiptAccountVendorCreate extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh', 'editReceiptVendor'];

    public $distributions = [];

    public $distribution_id = null;

    public $vendors = [];

    public $vendor = null;

    protected function rules()
    {
        return [
            'distribution_id' => 'required',
            'vendor.logged_in' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->distributions = Distribution::all();
    }

    public function editReceiptVendor($vendor_id)
    {
        $this->vendor = Vendor::with(['receipts', 'receipt_account'])->find($vendor_id);

        if (isset($this->vendor->receipt_account)) {
            $receipt_account = $this->vendor->receipt_account;
            if (! is_null($receipt_account->distribution_id)) {
                $this->distribution_id = $receipt_account->distribution_id;
            } else {
                $this->distribution_id = 'NO_PROJECT';
            }
        } else {
            $this->distribution_id = null;
        }

        // $this->vendor->logged_in = $this->vendor->receipt_account && $this->vendor->receipt_account->options ? ($this->vendor->receipt_account->options['access_token'] ? true : false) : false;
        $this->vendor->logged_in = isset($this->vendor->receipt_account->options) ? (isset($this->vendor->receipt_account->options['errors']) ? false : true) : false;

        $this->modal('receipt_account_vendor_form_modal')->show();
    }

    public function api_login()
    {
        $login_route = $this->vendor->receipts->first()->options['api_route'];
        $this->redirectRoute($login_route);
    }

    public function store()
    {
        $this->validate();

        if (is_numeric($this->distribution_id)) {
            $distribution_id = $this->distribution_id;
            $project_id = null;
        } else {
            //NO PROJECT
            $distribution_id = null;
            $project_id = 0;
        }

        if (is_null($this->vendor->receipt_account)) {
            //create new
            $receipt_account = new ReceiptAccount;
            $receipt_account->project_id = $project_id;
            $receipt_account->distribution_id = $distribution_id;
            $receipt_account->belongs_to_vendor_id = auth()->user()->vendor->id;
            $receipt_account->vendor_id = $this->vendor->id;
            $receipt_account->save();
        } else {
            //edit existing
            $receipt_account = $this->vendor->receipt_account;
            $receipt_account->project_id = $project_id;
            $receipt_account->distribution_id = $distribution_id;
            $receipt_account->save();
        }

        $this->modal('receipt_account_vendor_form_modal')->close();

        $this->dispatch('refreshComponent')->to('receipt-accounts.receipt-accounts-index');

        $this->dispatch('notify',
            type: 'success',
            content: 'Receipt Account Connected'
        );
    }

    public function render()
    {
        // $this->authorize('create', Expense::class);
        return view('livewire.receipt-accounts.vendor-create');
    }
}
