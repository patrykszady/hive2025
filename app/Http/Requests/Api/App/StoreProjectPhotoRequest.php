<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/photos — same file rule as
 * App\Http\Controllers\TimelapseController::store() (the web camera's direct
 * upload endpoint this mirrors), plus the same optional capture metadata.
 */
class StoreProjectPhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,heic,heif', 'max:20480'],
            'taken_at' => ['nullable', 'string', 'max:64'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ];
    }
}
