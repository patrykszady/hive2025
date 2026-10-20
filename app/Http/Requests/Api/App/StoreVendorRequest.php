<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Create New Vendor" — App\Livewire\Forms\VendorForm::store()'s rules
 * (business_name, business_type, business_email/website/phone) plus the
 * owner fields VendorCreate's nested "Add Owner" step collects for every
 * type but Retail (App\Livewire\Users\UserCreate::user_cell_find()/save()).
 * Resource-level authorization (VendorPolicy::create) happens in the
 * controller; a phone that doesn't resolve to a brand-new person without a
 * name/email throws from App\Services\People\AppPersonResolver, same as
 * the web's own required-field behavior once a phone search comes back
 * empty.
 */
class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:3'],
            'business_type' => ['required', Rule::in(['Sub', 'DBA', 'Retail', '1099'])],
            'business_email' => ['nullable', 'email', 'min:5'],
            'business_website' => ['nullable', 'url', 'max:255'],
            'business_phone' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip_code' => ['nullable', 'string', 'max:20'],

            'owner_cell_phone' => ['required_unless:business_type,Retail', 'digits:10'],
            'owner_first_name' => ['nullable', 'string', 'min:2'],
            'owner_last_name' => ['nullable', 'string', 'min:2'],
            'owner_email' => ['nullable', 'email', 'min:4'],
        ];
    }
}
