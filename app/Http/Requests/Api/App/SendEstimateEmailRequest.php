<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/email — mirrors
 * App\Livewire\Estimates\EstimateEmail's own rules. File attachments
 * (additionalAttachments) are left web-only; the app composes to/from/
 * subject/body plus the same include-PDF/XLSX/reimbursements flags. Real
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
            'include_estimate_pdf' => ['boolean'],
            'include_estimate_xlsx' => ['boolean'],
            'include_reimbursements_pdf' => ['boolean'],
        ];
    }
}
