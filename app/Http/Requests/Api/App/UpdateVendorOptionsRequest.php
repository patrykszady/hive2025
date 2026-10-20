<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/options — the same rules App\Livewire\Vendors\VendorOptions'
 * rules() enforce, minus the logo file (its own endpoint, see
 * UploadVendorLogoRequest).
 */
class UpdateVendorOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewOptions', Vendor::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'short_name' => ['nullable', 'string', 'max:100'],
            'sms_team_enabled' => ['required', 'boolean'],
            'sms_client_enabled' => ['required', 'boolean'],
            'sms_vendor_enabled' => ['required', 'boolean'],
            'default_contract_signers' => ['array'],
            'default_contract_signers.*' => ['integer', 'exists:users,id'],
            'business_hours_start' => ['required', 'date_format:H:i'],
            'business_hours_end' => ['required', 'date_format:H:i', 'after:business_hours_start'],
            'business_hours_days' => ['array'],
            'business_hours_days.*' => ['integer', 'between:1,7'],
        ];
    }
}
