<?php

namespace App\Livewire\ReceiptAccounts;

use App\Models\Distribution;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ReceiptAccountsIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refreshComponent' => '$refresh', 'addVendorToVendor'];

    public $vendor_keys = [];

    public $vendors = [];

    public $auth_vendor;

    public $distributions = [];

    // public $vendor_key = NULL;
    public $view = null;

    protected function rules()
    {
        return [
            // 'receipts_vendor.*.distribution_id' => 'required',
            // 'vendor_keys.*.distribution_id' => 'required',
            'vendors.*.receipt_accounts.0.distribution_id' => 'required',
        ];
    }

    public function mount()
    {
        $this->distributions = Distribution::all();

        $this->auth_vendor = auth()->user()->vendor;
        $this->vendors =
            Vendor::
            // withoutGlobalScopes()
            //     ->whereIn('id', $this->receipt_accounts)
                whereHas('receipts')
                // whereHas('receipt_accounts', function ($query) use ($auth_vendor) {
                //     return $query->where('belongs_to_vendor_id', $auth_vendor->id);
                //     })
                    ->with(['receipts', 'receipt_account'])
                    ->orderBy('business_name')
                    ->get()
                    ->each(function ($vendor, $key) {
                        if (! isset($vendor->receipt_account)) {
                            $vendor->type = 'Not Connected';
                            $vendor->status = 'Yellow';
                        } elseif ($vendor->receipts->first()->from_type == 4) {
                            if (isset($vendor->receipt_account->options['errors'])) {
                                $vendor->type = 'ERROR';
                                $vendor->status = 'Disabled';
                            } else {
                                $vendor->type = 'Login';
                                $vendor->status = 'Active';
                            }
                        } else {
                            $vendor->type = 'Email';
                            $vendor->status = 'Active';
                        }
                    });
        // dd($this->vendors);
        // dd($this->vendors->first()->receipt_accounts->first()->distribution ? $this->vendors->first()->receipt_accounts->first()->distribution->name : 'NO PROJECT');

        // $this->vendor_vendors_ids = auth()->user()->vendor->vendors->pluck('id')->toArray();
    }

    //add Existing Vendor to auth->user->vendor
    //6-16-2023 also used in VendorsForm ... COMBINE
    public function addVendorToVendor($vendor_id)
    {
        //Add existing Vendor to the logged-in-vendor
        //add $vendor to currently logged in vendor
        auth()->user()->vendor->vendors()->attach($vendor_id);

        //refreshComponent
        $this->mount();
        $this->render();

        $this->dispatchBrowserEvent('notify', [
            'type' => 'success',
            'content' => 'Vendor Added',
            'route' => 'vendors/'.$vendor_id,
        ]);
    }

    public function store()
    {
        dd($this);
        // foreach($this->receipts_vendor as $vendor_key => $vendor_distribution){
        //     $vendor = $this->vendors[$vendor_key];
        //     dd($vendor);
        //     dd($vendor->distribution_id);
        // }

        // return view('livewire.receipt-accounts.index');
    }

    public function render()
    {
        return view('livewire.receipt-accounts.index');
    }
}
