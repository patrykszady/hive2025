<?php

namespace App\Livewire\Forms;

use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class VendorForm extends Form
{
    use AuthorizesRequests;

    public ?Vendor $vendor;

    #[Rule('required|min:3')]
    public $business_name = null;

    #[Rule('required')]
    public $business_type = null;

    #[Rule('nullable|required_unless:business_type,Retail|min:3')]
    public $address = null;

    #[Rule('nullable|min:2')]
    public $address_2 = null;

    #[Rule('nullable|required_unless:business_type,Retail|min:3')]
    public $city = null;

    #[Rule('nullable|required_unless:business_type,Retail|min:2|max:2')]
    public $state = null;

    #[Rule('nullable|required_unless:business_type,Retail|digits:5', as: 'zip code')]
    public $zip_code = null;

    #[Rule('nullable|email|min:5', as: 'business email')]
    public $business_email = null;

    #[Rule('nullable|digits:10', as: 'business phone')]
    public $business_phone = null;

    #[Rule('nullable')]
    public $user_hourly_rate = null;

    #[Rule('nullable')]
    public $user_role = null;

    public function setVendor(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->business_name = $this->vendor->business_name;
        $this->business_type = $this->vendor->business_type;
        $this->address = $this->vendor->address;
        $this->address_2 = $this->vendor->address_2;
        $this->city = $this->vendor->city;
        $this->state = $this->vendor->state;
        $this->zip_code = $this->vendor->zip_code;
        $this->business_phone = $this->vendor->business_phone;
        $this->business_email = $this->vendor->business_email;
    }

    public function update()
    {
        $this->authorize('create', Vendor::class);
        $this->validate();

        $this->vendor->update([
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
        ]);

        return $this->vendor;
    }

    public function store()
    {
        $this->authorize('create', Vendor::class);
        $this->validate();

        return Vendor::create([
            'business_type' => $this->business_type,
            'business_name' => $this->business_name,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
        ]);
    }

    // protected $messages =
    // [
    //     'business_name_text' => 'Business Name must be at least 3 characters.'
    // ];
}
