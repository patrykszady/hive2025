<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/vendors — same rule as
 * App\Livewire\Projects\ProjectVendors::rules(). Authorization is checked
 * in the controller against the specific project (ProjectPolicy::update —
 * Admin of the project's OWNING vendor).
 */
class InviteProjectVendorRequest extends FormRequest
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
            'vendor_id' => ['required', 'exists:vendors,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Please select a vendor.',
        ];
    }
}
