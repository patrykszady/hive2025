<?php

namespace App\Livewire\Vendors;

use App\Livewire\Forms\VendorForm;
use App\Models\User;
use App\Models\Vendor;

use App\Services\GeoapifyService;
use App\Traits\HandlesAddresses;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorCreate extends Component
{
    use AuthorizesRequests, HandlesAddresses;

    public VendorForm $form;

    public $view_text = [
        'card_title' => 'Create Vendor',
        'button_text' => 'Create Vendor',
        'form_submit' => 'store',
    ];

    public bool $isRegistration = false;

    public Vendor $vendor;
    public User $user;
    public bool $has_user = false;

    public $business_name_text = null;
    public $vendor_add_type = null;
    public $via_vendor = null;
    public $vendor_id = null;
    public $existing_vendors = null;
    public $new_vendors_for_company = null;

    public $open_vendor_form = false;

    protected $listeners =
        [
            'refreshComponent' => '$refresh',
            'userVendor',
            'addVendorToCompany',
            'newVendor',
            'editVendor',
            'viaVendor',
        ];

    public function boot(GeoapifyService $geoapifyService)
    {
        $this->bootHandlesAddresses($geoapifyService);
    }

    protected function rules()
    {
        return [
            'business_name_text' => 'nullable|min:3|max:255',
        ];
    }

    #[Computed]
    public function showUserSection(): bool
    {
        return in_array($this->form->business_type, ['Sub', 'DBA', '1099'], true);
    }

    #[Computed]
    public function showAddressSection(): bool
    {
        return (bool) $this->user && ($this->form->business_type !== 'Retail');
    }

    public function editVendor(Vendor $vendor)
    {
        $this->form->setVendor($vendor);
    
        // Only set user for non-Retail vendors
        if ($vendor->business_type != 'Retail') {
            $this->user = $vendor->users()->first() ?? new User();
            $this->has_user = (bool) ($this->user->id ?? false);
        } else {
            $this->has_user = false;
        }
        
        $this->business_name_text = $vendor->business_name;
        $this->open_vendor_form = true;
        $this->vendor_add_type = $vendor->id;

        $this->view_text = [
            'card_title' => 'Update Vendor',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('vendors_form_modal')->show();
    }

    public function updatedBusinessNameText($value)
    {
        // Always validate (nullable allows empty values)
        $this->validateOnly('business_name_text');

        $existing_vendor_ids = auth()->user()->vendor->vendors->pluck('id')->toArray();

        $this->existing_vendors =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$value}%")
                ->whereIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->new_vendors_for_company =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$value}%")
                ->whereNotIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->form->reset();
        $this->form->business_name = $value;
        $this->open_vendor_form = false;
    }

    public function viaVendor(User $user, $business_name)
    {
        $this->user = $user;
        $this->has_user = true;
        $this->form->business_name = $business_name;
        $this->business_name_text = $business_name;
        $this->form->business_type = '1099';
        $this->form->user_hourly_rate = 0;
        $this->form->user_role = 1;

        $this->via_vendor = true;
        $this->open_vendor_form = true;

        $this->modal('vendors_form_modal')->show();
    }

    public function newVendor()
    {
        $this->vendor_add_type = 'NEW';
        $this->modal('vendors_form_modal')->show();
    }

    public function openVendorForm()
    {
        $this->open_vendor_form = true;
    }

    //add Existing Vendor to auth->user->vendor (Company)
    //used to be addVendorToVendor
    public function addVendorToCompany($vendor_id)
    {
        //add $vendor to currently logged in vendor (company)
        auth()->user()->vendor->vendors()->syncWithoutDetaching([$vendor_id]);

        $this->redirectRoute('vendors.show', ['vendor' => $vendor_id], navigate: true);
        // $this->dispatch('refreshComponent')->to('vendors.vendors-index');
        // $this->modal('vendors_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Added.',
            // route / href / wire:click
            // route: 'vendors/'.$vendor_id,
            text: '',
        );
    }

    //when Creating NEW Vendor
    public function userVendor($user_info)
    {
        $this->user = User::findOrFail($user_info['id']);
        $this->has_user = true;
        $this->form->user_hourly_rate = $user_info['hourly_rate'];
        $this->form->user_role = $user_info['role'];
    }

    public function edit()
    {
        $vendor = $this->form->update();

        $this->modal('vendors_form_modal')->close();
        $this->dispatch('refreshComponent')->to('vendors.vendor-details');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Updated',
            // route / href / wire:click
            text: $vendor->name,
        );
    }

    public function store()
    {
        if (isset($this->vendor->id)) {
            //attach vendor to auth->user->vendor (logged in/working vendor/Company)
            $vendor = $this->vendor;
            auth()->user()->vendor->vendors()->syncWithoutDetaching([$vendor->id]);
        //NEW VENDOR
        } else {
            $vendor = $this->form->store();

            //Add existing Vendor to the logged-in-vendor/company || add $vendor to currently logged in vendor
            auth()->user()->vendor->vendors()->syncWithoutDetaching([$vendor->id]);

            if ($vendor->business_type != 'Retail') {
                $user = $this->user;

                // attach to new $vendor with role_id of 1/admin (default on Model)
                $user->vendors()->attach(
                    $vendor->id, [
                        'role_id' => $this->form->user_role, //default on Model table
                        'hourly_rate' => $this->form->user_hourly_rate,
                        'start_date' => today()->format('Y-m-d'),
                    ]
                );
            }
        }

        if ($this->via_vendor) {
            //dispatch back to UserCreate
            $this->dispatch('ViaVendorId', via_vendor_id: $vendor->id)->to('users.user-create');
        }

        // Notify VendorsIndex to prepend a highlighted row for this new vendor (session-only)
        $this->dispatch('VendorCreated', vendor: [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'business_type' => $vendor->business_type,
            'ytd_expense_sum' => (float) ($vendor->ytd_expense_sum ?? 0),
        ])->to('vendors.vendors-index');

        //reset component
        $this->modal('vendors_form_modal')->close();
        $this->dispatch('refreshComponent')->self();
        $this->form->reset();


        // route: 'vendors/' . $vendor->id
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Added.',
            // route / href / wire:click
            text: $vendor->name . ' was created.',
        );
    }

    public function render()
    {
        return view('livewire.vendors.form');
    }
}
