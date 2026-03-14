<?php

namespace App\Livewire\ReceiptAccounts;

use App\Models\Distribution;
use App\Models\Vendor;
use App\Models\TransactionBulkMatch;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Computed;
use Livewire\Component;

class ReceiptAccountsIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refreshComponent' => '$refresh', 'addVendorToVendor'];

    public $new_vendors = [];
    public $distributions = [];

    public $vendor_id = NULL;
    public $view = NULL;

    protected function rules()
    {
        return [
            'vendor_id' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->distributions = Distribution::all();
        $this->new_vendors = Vendor::where('business_type', 'Retail')->whereNotIn('id', $this->vendors->pluck('id')->toArray())->get();
    }

    #[Computed]
    public function vendors()
    {
        $vendors = Vendor::query()
            ->with(['receipt_account'])
            ->withCount('transactions_bulk_match')
            // ->withoutGlobalScopes()
            //   ->whereIn('id', $this->receipt_accounts)
            ->whereHas('receipt_account')
            // whereHas('receipt_accounts', function ($query) use ($auth_vendor) {
            //     return $query->where('belongs_to_vendor_id', $auth_vendor->id);
            //     })
                // ->with(['receipts', 'receipt_account'])
            ->orderBy('business_name')
            ->get();
                // ->each(function ($vendor, $key) {
                //     if (! isset($vendor->receipt_account)) {
                //         $vendor->type = 'Not Connected';
                //         $vendor->status = 'Yellow';
                //     } elseif ($vendor->receipts->first()->from_type == 4) {
                //         if (isset($vendor->receipt_account->options['errors'])) {
                //             $vendor->type = 'ERROR';
                //             $vendor->status = 'Disabled';
                //         } else {
                //             $vendor->type = 'Login';
                //             $vendor->status = 'Active';
                //         }
                //     } else {
                //         $vendor->type = 'Email';
                //         $vendor->status = 'Active';
                //     }
                // });

        return $vendors;
    }

    public function addVendor()
    {
        $vendor = Vendor::findOrFail($this->vendor_id);
        $this->vendors->push($vendor);

        //$dispatchTo('receipt-accounts.receipt-account-vendor-create', 'editReceiptVendor', { vendor: {{$vendor}} })
        // $this->dispatch('editReceiptVendor')->to(Dashboard::class);
        $this->dispatch('editReceiptVendor', vendor: $vendor->id)->to('receipt-accounts.receipt-account-vendor-create');
        $this->vendor_id = NULL;
    }

    //add Existing Vendor to auth->user->vendor
    //6-16-2023 also used in VendorsForm ... COMBINE
    public function addVendorToVendor($vendor_id)
    {
        //Add existing Vendor to the logged-in-vendor
        //add $vendor to currently logged in vendor
        auth()->user()->vendor->vendors()->attach($vendor_id);

        // $this->dispatchBrowserEvent('notify', [
        //     'type' => 'success',
        //     'content' => 'Vendor Added',
        //     'route' => 'vendors/'.$vendor_id,
        // ]);
    }

    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);
        return view('livewire.receipt-accounts.index');
    }
}
