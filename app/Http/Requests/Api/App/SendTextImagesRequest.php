<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/message-images/text — same fields as
 * the web studio's "Text Selected" modal
 * (App\Livewire\Projects\TimelapseStudio::textImages()).
 */
class SendTextImagesRequest extends FormRequest
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
            'thread_id' => ['required', 'integer'],
            'frame_ids' => ['nullable', 'array'],
            'frame_ids.*' => ['integer'],
            'message_urls' => ['nullable', 'array'],
            'message_urls.*' => ['string'],
            'note' => ['nullable', 'string', 'max:1200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'thread_id.required' => 'Pick a conversation.',
        ];
    }
}
