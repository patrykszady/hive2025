<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/app/v1/categories/vendors/{vendor}/sheets-type — VendorCategoryCard::updateSheetsType(). */
class UpdateVendorSheetsTypeRequest extends FormRequest
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
            'sheets_type' => ['nullable', 'string', 'max:255'],
        ];
    }
}
