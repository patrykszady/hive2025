<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/checks/{check}/images — mirrors
 * App\Livewire\Checks\CheckShow::uploadCheckImage()'s own validation
 * exactly (a photo of the check, or the bank's single-check PDF).
 */
class UploadCheckImageRequest extends FormRequest
{
    /**
     * Real authorization (CheckPolicy::update()) happens in the controller
     * — same pattern as StorePaymentRequest.
     */
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file first; if you did, it is still uploading or the upload failed.',
            'file.mimes' => 'Upload a PDF or a photo (JPG, PNG or WebP). iPhone HEIC photos are not supported.',
        ];
    }
}
