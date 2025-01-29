<?php

namespace App\Livewire\Vendors;

use App\Livewire\Forms\VendorForm;
use App\Models\User;
use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class VendorCreate extends Component
{
    use AuthorizesRequests;

    public VendorForm $form;

    public $view_text = [
        'card_title' => 'Create Vendor',
        'button_text' => 'Create Vendor',
        'form_submit' => 'store',
    ];

    public Vendor $vendor;

    public $user = null;

    public $vendor_add_type = null;

    public $via_vendor = null;

    public $address = null;

    public $team_member = '';

    public $user_vendors = null;

    public $vendor_id = null;

    public $user_vendor_id = null;

    public $business_name_text = null;

    public $existing_vendors = null;

    public $add_vendors_vendor = null;

    public $open_vendor_form = false;

    protected $listeners =
        [
            'refreshComponent' => '$refresh',
            'userVendor',
            'addVendorToVendor',
            'newVendor',
            'vendorModal',
            'editVendor',
            'resetModal',
            'viaVendor',
        ];

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

    public function vendorModal($team_member = null)
    {
        // $this->form->reset();
        // $this->business_name_text = NULL;
        // $this->vendor = Vendor::make();
        // $this->vendor->business_name = NULL;
        // $this->business_name_text = NULL;
        // $this->modal('vendors_form_modal')->close();
        // $this->resetModal();

        //5-18-2023 to reset modal if was clicked away and not CANCEL was clicked...whyyyyy
        // if(is_numeric($team_member)){
        //     $this->team_member = $team_member;

        //     $user_info = [
        //         'id' => $team_member,
        //         'hourly_rate' => 0,
        //         'role' => 1
        //     ];

        //     $this->userVendor($user_info);

        //     $this->vendor->business_name = $this->user->full_name;
        //     $this->business_name_text = $this->vendor->business_name;
        // }else{

        //     //role and hourly here for new vendor?
        //     // $this->team_member = 'index';
        //     // $this->user = User::make();
        // }

        $this->team_member = 'index';
        $this->modal('vendors_form_modal')->show();
    }

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
        $this->address = true;

        $this->via_vendor = true;

        $this->modal('vendors_form_modal')->show();
    }

    public function editVendor(Vendor $vendor)
    {
        //5-18-2023 to reset modal if was clicked away and not CANCEL was clicked...whyyyyy
        // $this->resetModal();
        $this->vendor = $vendor;

        $this->form->setVendor($this->vendor);
        $this->user = $this->vendor->users()->first();
        $this->business_name_text = $vendor->business_name;
        $this->open_vendor_form = true;
        if ($this->vendor->business_type != 'Retail') {
            $this->address = true;
        }

        $this->view_text = [
            'card_title' => 'Update Vendor',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('vendors_form_modal')->show();
    }

    public function UpdatedBusinessNameText($value)
    {
        $existing_vendor_ids = auth()->user()->vendor->vendors->pluck('id')->toArray();

        $this->existing_vendors =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$this->business_name_text}%")
                ->whereIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->add_vendors_vendor =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$this->business_name_text}%")
                ->whereNotIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->form->reset();
        $this->form->business_name = $value;
        $this->open_vendor_form = false;
    }

    public function updated($field)
    {
        $this->validateOnly($field);

        if ($field == 'vendor.business_type') {
            if (in_array($this->vendor->business_type, ['Sub', '1099', 'DBA'])) {
                if (isset($this->user->id)) {
                    $this->address = true;
                }
                // $this->user = $this->user;
                // }elseif($this->vendor->business_type == 'Retail'){
                //     $this->user = NULL;
            } elseif ($this->vendor->business_type == 'Retail') {
                $this->user = null;
                $this->address = null;
                $this->user_vendors = null;
            } else {
                $this->address = null;
            }
        }
    }

    // Everthing in top pulbic should be reset here
    public function resetModal()
    {
        $this->form->reset();
        $this->vendor = Vendor::make();
        // $this->vendor->business_name = NULL;
        $this->business_name_text = null;
        $this->modal('vendors_form_modal')->show();
        $this->user = null;
        $this->address = null;
        $this->user_vendors = null;
        $this->vendor_id = null;
        $this->user_vendor_id = null;
    }

    public function newVendor()
    {
        // $this->resetModal();
        $this->vendor->business_name = $this->business_name_text;

        // dd($this->vendor->business_name);
        // dd($this->vendor);
        // dd('in new vendor');
        //remove existing and add vendor and top textbox AND open rest of form
    }

    //add Existing Vendor to auth->user->vendor
    public function addVendorToVendor($vendor_id)
    {
        //Add existing Vendor to the logged-in-vendor
        //add $vendor to currently logged in vendor
        auth()->user()->vendor->vendors()->attach($vendor_id);

        // $this->vendor_id = $vendor_id;
        // $this->mount();
        // $this->render();
        $this->modal('vendors_form_modal')->close();
        // $this->vendor = Vendor::make();
        // $this->resetModal();
        $this->dispatch('refreshComponent')->to('vendors.vendors-index');

        //notification
        $this->dispatch('notify',
            type: 'success',
            content: 'Vendor Added',
            route: 'vendors/'.$vendor_id
        );
    }

    //when Creating NEW Vendor
    public function userVendor($user_info)
    {
        $this->user = User::findOrFail($user_info['id']);
        $this->form->user_hourly_rate = $user_info['hourly_rate'];
        $this->form->user_role = $user_info['role'];

        $this->user_vendors = $this->user->vendors()->get()->unique('id');
        $this->address = true;
    }

    public function edit()
    {
        dd($this);
        $vendor = $this->form->update();

        $this->modal('vendors_form_modal')->close();

        $this->dispatch('refreshComponent')->to('vendors.vendor-details');

        $this->dispatch('notify',
            type: 'success',
            content: 'Vendor Updated',
            route: 'vendors/'.$vendor->id
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
        // $this->resetModal();
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
