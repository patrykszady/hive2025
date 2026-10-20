<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddMessagePhotosToProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * project_id is a plain existence check — ProjectScope (the acting
     * user's own vendor) narrows what actually resolves, same reasoning as
     * App\Livewire\Sms\SmsConversation::addImagesToProject()'s comment.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
        ];
    }
}
