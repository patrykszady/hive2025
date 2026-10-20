<?php

namespace App\Http\Controllers;

use App\Models\AuditRequest;
use App\Models\Vendor;
use App\Services\Audits\AuditReport;
use Illuminate\Http\Request;

/**
 * Signed, no-session audit Excel export — see routes/web.php's
 * vendor-docs.audit.xlsx.signed for the route this backs. Minted by GET
 * /api/app/v1/vendor-docs/audit/export-xlsx-url (see
 * Api\App\V1\VendorDocAuditController::exportXlsxUrl), the same pattern
 * as projects.finances.reimbursements.signed: the `signed` middleware
 * alone is the gate, so this method itself does no auth check, and
 * $vendor is looked up without the VendorScope (it is the one named IN
 * the signature, not the signed-in session's).
 */
class VendorDocsAuditXlsxController extends Controller
{
    public function signed(Request $request, int $vendor)
    {
        $company = Vendor::withoutGlobalScopes()->find($vendor);
        abort_unless($company, 404);

        $startDate = (string) $request->query('start_date');
        $endDate = (string) $request->query('end_date');
        $type = $request->query('type') ?: null;
        $bankAccountIds = array_map('intval', (array) $request->query('bank_account_ids', []));
        $coverageDate = $type === 'workers' ? AuditRequest::coverageDateFor($endDate) : null;

        $report = new AuditReport($company->id, $startDate, $endDate, $type, $bankAccountIds, $coverageDate);
        $filename = $report->filenameBase().'.xlsx';

        return response($report->xlsx(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
