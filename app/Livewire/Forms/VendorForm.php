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

    #[Rule('nullable|email|min:5', as: 'business email')]
    public $business_email = null;

    // No Rule attribute for business_phone, we'll use rules() method instead
    public $business_phone = null;

    #[Rule('nullable')]
    public $user_hourly_rate = null;

    #[Rule('nullable')]
    public $user_role = null;

    /**
     * Define rules for the business phone field
     */
    public function rules()
    {
        return [
            'business_phone' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    // Only count the digits
                    $digitsOnly = preg_replace('/[^0-9]/', '', $value ?: '');
                    
                    // Allow empty values (handled by 'nullable')
                    if (empty($digitsOnly)) {
                        return;
                    }
                    
                    // Check if we have exactly 10 digits
                    if (strlen($digitsOnly) !== 10) {
                        $fail('The business phone must contain exactly 10 digits.');
                    }
                },
            ],
        ];
    }

    // Keep the setVendor method as is
    public function setVendor(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->business_name = $this->vendor->business_name;
        $this->business_type = $this->vendor->business_type;
        $this->component->address_1 = $this->vendor->address;
        $this->component->address_2 = $this->vendor->address_2;
        $this->component->city = $this->vendor->city;
        $this->component->state = $this->vendor->state;
        $this->component->zip_code = $this->vendor->zip_code;
        $this->business_phone = $this->vendor->business_phone;
        $this->business_email = $this->vendor->business_email;
    }

    public function update()
    {
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
            'category_id' => $this->category_id, // Make sure this is included
        ]);
        
        return $this->vendor;
    }

    public function store()
    {
        $this->authorize('create', Vendor::class);
        // This will combine rules from attributes and the rules() method
        $this->validate();

        return Vendor::create([
            'business_type' => $this->business_type,
            'business_name' => $this->business_name,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
        ]);
    }
}
