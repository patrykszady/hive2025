<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/{lead}/attach-user — mirrors
 * App\Livewire\Leads\LeadCreate::attachUser()/updatedSelectedUserId():
 * link an existing user (one of the "User" dropdown's candidates) as this
 * lead's contact.
 */
class AttachLeadUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }
}
