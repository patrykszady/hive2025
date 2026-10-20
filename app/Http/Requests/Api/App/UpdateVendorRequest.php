<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Edit Vendor" — App\Livewire\Forms\VendorForm::update()'s rules exactly.
 * business_type AND business_name are intentionally absent: the web's edit
 * form keeps the type radio group disabled once a vendor exists, and keeps
 * the Business Name input disabled once it HAS a value — every existing
 * vendor does, StoreVendorRequest requires one — so there is nothing here
 * to change either with (App\Services\Vendors\AppVendorDirectory::
 * updateVendor() reuses both from the vendor row itself). Resource-level
 * authorization (VendorPolicy::update) happens in the controller.
 */
class UpdateVendorRequest extends FormRequest
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
        // Mirrors the web's footer submit-button gate (form.blade.php):
        // "businessType === 'Retail' || (businessType !== 'Retail' && zipCode)".
        // business_type itself isn't submittable here (it's locked), so this
        // reads the vendor's own value to decide whether zip is required —
        // a bogus/missing id just leaves zip optional; the controller's own
        // findOrFail() is what actually 404s that case.
        $isRetail = Vendor::query()->find($this->route('vendor'))?->business_type === 'Retail';

        return [
            'business_email' => ['nullable', 'email', 'min:5'],
            'business_website' => ['nullable', 'url', 'max:255'],
            'business_phone' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip_code' => [$isRetail ? 'nullable' : 'required', 'nullable', 'string', 'max:20'],
        ];
    }
}
