<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/app/v1/vendor-docs/audit/statement-gaps — the months Plaid has
 * no statement for yet, per bank, read-only. Mirrors
 * App\Livewire\VendorDocs\AuditShow's $statementGaps (built from
 * download_bank_statements()/fetchMissingStatements()) without the PDF
 * download itself — see App\Services\VendorDocs\AppAuditActions.
 */
class ShowAuditStatementGapsRequest extends FormRequest
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
