<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/vendor-docs/audit/ewccv/verify — "Check EWCCV as of
 * <date>" on the web's audit page — AuditShow::verify_coverage_on_ewccv().
 */
class VerifyAuditEwccvRequest extends FormRequest
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
