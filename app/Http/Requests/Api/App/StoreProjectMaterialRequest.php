<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/materials — same file rule as
 * App\Livewire\Projects\ProjectMaterials::rules() (the web's upload modal
 * this mirrors), plus the optional "Belongs To Vendor" selector.
 */
class StoreProjectMaterialRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'belongs_to_vendor_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.mimes' => 'File must be a PDF, JPG, or PNG.',
            'file.max' => 'File must be less than 20MB.',
        ];
    }
}
