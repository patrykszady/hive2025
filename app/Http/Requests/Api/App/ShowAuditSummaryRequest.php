<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/app/v1/vendor-docs/audit — mirrors AuditIndex::rules(). */
class ShowAuditSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', VendorDoc::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'type' => ['nullable', 'in:workers,general,professional'],
            'bank_account_ids' => ['array'],
            'bank_account_ids.*' => ['integer'],
        ];
    }
}
