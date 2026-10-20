<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\ProjectDocumentGenerator;

/**
 * Signed, no-session project reimbursements PDF — see routes/web.php's
 * projects.finances.reimbursements.signed for the route this backs. Minted
 * by GET /api/app/v1/projects/{id}/finances/reimbursements-pdf-url (see
 * Api\App\V1\ProjectFinancesController::reimbursementsPdfUrl), the same
 * pattern as estimates.pdf.signed: the `signed` middleware alone is the
 * gate, so this method itself does no auth check.
 */
class ProjectReimbursementsPdfController extends Controller
{
    public function signed(int $project)
    {
        $found = Project::withoutGlobalScopes()->find($project);

        abort_unless($found, 404);

        $document = ProjectDocumentGenerator::generateReimbursements($found);

        return response($document['binary'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$document['filename'].'"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
