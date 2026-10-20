<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/email — mirrors
 * App\Livewire\Estimates\EstimateEmail's own rules exactly: to/from/
 * subject/body, the include-PDF/XLSX/reimbursements flags, which template
 * was used (for tracking only — the app sends the subject/body it already
 * filled in, same as the web), showChanges, and up to 5 extra attachments
 * (max 10MB each, same limits as the web's WithFileUploads rule). Real
 * authorization ('view' on the estimate — the same, laxer, gate the web
 * uses for sending) happens in the controller.
 */
class SendEstimateEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'from' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'template_id' => ['nullable', 'integer'],
            'include_estimate_pdf' => ['boolean'],
            'include_estimate_xlsx' => ['boolean'],
            'include_reimbursements_pdf' => ['boolean'],
            'show_changes' => ['boolean'],
            'additional_attachments' => ['array', 'max:5'],
            'additional_attachments.*' => ['file', 'max:10240'],
        ];
    }
}
