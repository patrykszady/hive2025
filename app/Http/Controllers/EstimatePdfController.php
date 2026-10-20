<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Support\EstimateDocumentGenerator;

/**
 * Signed, no-session estimate PDF — see routes/web.php's estimates.pdf.signed
 * for the route this backs. Minted by GET /api/app/v1/estimates/{id}/pdf-url
 * (see Api\App\V1\EstimateController::pdfUrl), the same pattern as
 * timelapse.frame.signed and expenses.receipts.signed: the `signed`
 * middleware alone is the gate, so this method itself does no auth check.
 */
class EstimatePdfController extends Controller
{
    public function signed(int $estimate)
    {
        $found = Estimate::withoutGlobalScopes()
            ->with(['estimate_sections.estimate_line_items.allowances', 'vendor', 'project.client.users', 'project.latestStatus'])
            ->find($estimate);

        abort_unless($found && $found->vendor, 404);

        $result = EstimateDocumentGenerator::generate($found);

        return response($result['binary'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
