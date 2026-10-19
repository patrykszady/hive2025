<?php

namespace App\Http\Requests\Api\App;

use App\Models\EmailTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/email-templates — the same rules
 * App\Livewire\EmailTemplates\EmailTemplateForm::save() enforces: subject
 * is required for every type except 'contract'.
 */
class StoreEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', EmailTemplate::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'type' => ['required', 'string', 'in:estimate,invoice,contract,lead'],
            'subject' => $this->input('type') === 'contract'
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
        ];
    }
}
