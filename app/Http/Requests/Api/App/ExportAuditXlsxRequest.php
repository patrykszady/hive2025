<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/app/v1/vendor-docs/audit/export-xlsx-url — mints a time-limited
 * signed link to the same Excel AuditShow::export_xlsx() streams on the
 * web (App\Services\Audits\AuditReport::xlsx()), reachable without a web
 * session — see routes/web.php's vendor-docs.audit.xlsx.signed and
 * App\Http\Controllers\VendorDocsAuditXlsxController.
 */
class ExportAuditXlsxRequest extends FormRequest
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
