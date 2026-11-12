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
            // The collection (timelapse/album) this shot targets — the
            // Collections hub's selected camera target. A missing/foreign
            // id falls back to the catch-all album server-side.
            'collection_id' => ['nullable', 'integer'],
            // Chosen by the app once per shot and sent again on every retry
            // of it — the second arrival gets the frame the first stored.
            'client_upload_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_upload_id.regex' => 'The upload id may only contain letters, numbers, dashes and underscores.',
            'client_upload_id.max' => 'The upload id may not be longer than 64 characters.',
        ];
    }
}
