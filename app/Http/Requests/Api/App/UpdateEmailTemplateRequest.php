<?php

namespace App\Http\Requests\Api\App;

use App\Models\EmailTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/email-templates/{template} — mirrors
 * EmailTemplateForm::save()'s update branch (same rules as creating, plus
 * EmailTemplatePolicy::update, which refuses a template outside the
 * signed-in admin's own company).
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof EmailTemplate && (bool) $this->user()?->can('update', $template);
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
