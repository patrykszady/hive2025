<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/vendor-docs/audit/statements/{bank}/refresh — "Request
 * from <bank>" / "Try again with <bank>" on the web's statement-gaps
 * callout — AuditShow::fetchMissingStatements().
 */
class RefreshAuditStatementsRequest extends FormRequest
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
            'bank_account_ids' => ['required', 'array', 'min:1'],
            'bank_account_ids.*' => ['integer'],
        ];
    }
}
