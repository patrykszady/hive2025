<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/app/v1/options/logo — mirrors VendorOptions' `logo` rule. */
class UploadVendorLogoRequest extends FormRequest
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
            'logo' => ['required', 'image', 'max:10240'],
        ];
    }
}
