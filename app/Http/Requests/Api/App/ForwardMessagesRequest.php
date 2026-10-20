<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ForwardMessagesRequest extends FormRequest
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
     * Mirrors App\Livewire\Sms\SmsConversation::forwardMessages()'s own
     * validate() call; the target thread's accessibility to the acting user
     * is re-checked in the controller (App\Services\Sms\ThreadForwarder's
     * candidateThreads()), same as the web's forwardableThreads() check.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_thread_id' => ['required', 'integer', 'exists:sms_group_threads,id'],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer'],
        ];
    }
}
