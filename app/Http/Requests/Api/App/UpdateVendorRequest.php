<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Edit Vendor" — App\Livewire\Forms\VendorForm::update()'s rules exactly.
 * business_type is intentionally absent: the web's edit form keeps that
 * field disabled once a vendor exists, so there is nothing here to change
 * it with. Resource-level authorization (VendorPolicy::update) happens in
 * the controller.
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
        return [
            'business_name' => ['required', 'string', 'min:3'],
            'business_email' => ['nullable', 'email', 'min:5'],
            'business_website' => ['nullable', 'url', 'max:255'],
            'business_phone' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
