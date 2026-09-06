<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The project's reimbursement receipts as a PDF, for the "Download" link
 * printed beside Reimbursements in the estimate PDF. The client opens that
 * document from an email or a download folder with no session, so the
 * signed URL is the credential (the `signed` middleware on the route).
 */
class ReimbursementsPdfController extends Controller
{
    public function __invoke(Request $request, int $project): StreamedResponse
    {
        $project = Project::withoutGlobalScopes()->findOrFail($project);

        abort_if((float) ($project->financesForVendor((int) $project->belongs_to_vendor_id)['reimbursments'] ?? 0) <= 0, 404);

        $document = ProjectDocumentGenerator::generateReimbursements($project);

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
