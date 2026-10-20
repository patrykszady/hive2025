<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/collections/from-selection — same
 * curation action as the web studio's "Timelapse N" button in select mode
 * (App\Livewire\Projects\TimelapseStudio::createTimelapseFromSelection()).
 */
class CreateTimelapseFromSelectionRequest extends FormRequest
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
            'frame_ids' => ['required', 'array', 'min:1'],
            'frame_ids.*' => ['integer'],
        ];
    }
}
