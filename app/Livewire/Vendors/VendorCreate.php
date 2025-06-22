<?php

namespace App\Livewire\Vendors;

use App\Livewire\Forms\VendorForm;
use App\Services\GooglePlacesService;
use App\Traits\HandlesAddresses;

use App\Models\User;
use App\Models\Vendor;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

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

    public Vendor $vendor;
    public $hasSearched = false;
    public $business_name_text = null;

    public $user = null;

    public $vendor_add_type = null;

    public $via_vendor = null;

    public $address_isset = null;

    public $team_member = '';

    public $user_vendors = null;

    public $vendor_id = null;

    public $user_vendor_id = null;

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

    protected $googlePlacesService;

    //so that protected $googlePlacesService can be initialized
    public function boot(GooglePlacesService $googlePlacesService)
    {
        $this->bootHandlesAddresses($googlePlacesService);
    }

    protected function rules()
    {
        return [
            'business_name_text' => 'nullable|min:3|max:255',
        ];
    }

    public function mount()
    {
        if (isset($this->vendor->id)) {
            $this->vendor = $this->vendor;
            $this->vendor_add_type = $this->vendor_id;
            // $this->view_text = [
            //     'card_title' => 'Update Vendor',
            //     'button_text' => 'Update Vendor',
            //     'form_submit' => 'update',
            // ];
        } else {
            $this->vendor = Vendor::make();
            $this->vendor_add_type = 'NEW';
            // $this->view_text = [
            //     'card_title' => 'Create Vendor',
            //     'button_text' => 'Create Vendor',
            //     'form_submit' => 'store',
            // ];
        }
    }

    // public function updated($field)
    // {
    //     $this->validateOnly($field);

    //     if ($field == 'vendor.business_type') {
    //         if (in_array($this->vendor->business_type, ['Sub', '1099', 'DBA'])) {
    //             if (isset($this->user->id)) {
    //                 $this->address_isset = true;
    //             }
    //             // $this->user = $this->user;
    //             // }elseif($this->vendor->business_type == 'Retail'){
    //             //     $this->user = NULL;
    //         } elseif ($this->vendor->business_type == 'Retail') {
    //             $this->user = null;
    //             $this->address_isset = null;
    //             $this->user_vendors = null;
    //         } else {
    //             $this->address_isset = null;
    //         }
    //     }
    // }

    public function viaVendor(User $user, $business_name)
    {
        $this->user = $user;
        $this->form->business_name = $business_name;
        $this->business_name_text = $business_name;
        $this->form->business_type = '1099';

        //similar to $this->userVendor($user_info);
        $this->form->user_hourly_rate = 0;
        $this->form->user_role = 1;

        $this->user_vendors = $this->user->vendors()->unique()->get();
        $this->address_isset = true;

        $this->via_vendor = true;

        $this->modal('vendors_form_modal')->show();
    }

    public function editVendor(Vendor $vendor)
    {
        //5-18-2023 to reset modal if was clicked away and not CANCEL was clicked...whyyyyy
        $this->vendor = $vendor;

        $this->form->setVendor($this->vendor);
        $this->user = $this->vendor->users()->first();
        $this->business_name_text = $vendor->business_name;
        $this->open_vendor_form = true;
        if ($this->vendor->business_type != 'Retail') {
            $this->address_isset = true;
        }

        $this->view_text = [
            'card_title' => 'Update Vendor',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('vendors_form_modal')->show();
    }

    public function updatedBusinessNameText($value)
    {
        $trimmedValue = trim($value);

        // Always validate (nullable allows empty values)
        $this->validateOnly('business_name_text');

        // Reset everything if empty or validation failed
        if (strlen($trimmedValue) === 0) {
            $this->hasSearched = false;
            $this->existing_vendors = collect();
            $this->new_vendors_for_company = collect();
            $this->form->reset();
            return;
        }

        // Check if there are validation errors (length < 3)
        if ($this->getErrorBag()->has('business_name_text')) {
            $this->hasSearched = false;
            $this->existing_vendors = collect();
            $this->new_vendors_for_company = collect();
            $this->form->reset();
            return;
        }

        // If validation passes, mark that we've performed a search
        $this->hasSearched = true;

        $existing_vendor_ids = auth()->user()->vendor->vendors->pluck('id')->toArray();

        $this->existing_vendors =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$trimmedValue}%")
                ->whereIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->new_vendors_for_company =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$trimmedValue}%")
                ->whereNotIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->form->reset();
        $this->form->business_name = $trimmedValue;
        $this->open_vendor_form = false;
    }

    public function newVendor()
    {
        $this->modal('vendors_form_modal')->show();
        // $this->vendor->business_name = $this->business_name_text;

        //remove existing and add vendor and top textbox AND open rest of form
    }

    //add Existing Vendor to auth->user->vendor (Company)
    //used to be addVendorToVendor
    public function addVendorToCompany($vendor_id)
    {
        //add $vendor to currently logged in vendor (company)
        auth()->user()->vendor->vendors()->attach($vendor_id);

        $this->redirectRoute('vendors.show', ['vendor' => $vendor_id]);
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
        $this->form->user_hourly_rate = $user_info['hourly_rate'];
        $this->form->user_role = $user_info['role'];

        $this->user_vendors = $this->user->vendors()->get()->unique('id');
        $this->address_isset = true;
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
            heading: 'Vendor Updated.',
            // route / href / wire:click
            text: '',
        );
    }

    public function store()
    {
        if (isset($this->vendor->id)) {
            //attach vendor to auth->user->vendor (logged in/working vendor)
            $vendor = $this->vendor;
            auth()->user()->vendor->vendors()->attach($vendor);
        } else {
            $vendor = $this->form->store();
            //NEW VENDOR

            //Add existing Vendor to the logged-in-vendor || add $vendor to currently logged in vendor
            auth()->user()->vendor->vendors()->attach($vendor->id);

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

        //reset component
        $this->modal('vendors_form_modal')->close();
        $this->dispatch('refreshComponent')->self();

        $this->form->reset();
        // $this->dispatch('via', 'vendor')->to('users.users-form');
        $this->dispatch('refreshComponent')->to('vendors.vendors-index');

        // route: 'vendors/' . $vendor->id
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Added.',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.vendors.form', [
            'view_text' => $this->view_text,
        ]);
    }
}
