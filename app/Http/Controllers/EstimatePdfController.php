<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Estimate;
use App\Support\EstimateDocumentGenerator;
use Illuminate\Support\Facades\Storage;

/**
 * Signed, no-session estimate documents — see routes/web.php's
 * estimates.pdf.signed/estimates.xlsx.signed/estimates.signed-contract.signed
 * for the routes these back. Minted by GET /api/app/v1/estimates/{id}/pdf-url,
 * xlsx-url and signed-contract-url (see Api\App\V1\EstimateController), the
 * same pattern as timelapse.frame.signed and expenses.receipts.signed: the
 * `signed` middleware alone is the gate, so these methods do no auth check
 * of their own.
 */
class EstimatePdfController extends Controller
{
    /**
     * Mirrors App\Livewire\Estimates\EstimateShow::create_pdf($type) — $type
     * is 'estimate', 'invoice' or 'work order' (query, part of the signature
     * via URL::temporarySignedRoute's extra params), EstimateDocumentGenerator
     * title-cases it the same way either caller passes it.
     */
    public function signed(Request $request, int $estimate)
    {
        $found = Estimate::withoutGlobalScopes()
            ->with(['estimate_sections.estimate_line_items.allowances', 'vendor', 'project.client.users', 'project.latestStatus'])
            ->find($estimate);

        abort_unless($found && $found->vendor, 404);

        $type = (string) $request->query('type', 'estimate');
        if (! in_array($type, ['estimate', 'invoice', 'work order'], true)) {
            $type = 'estimate';
        }

        $result = EstimateDocumentGenerator::generate(
            $found,
            $type,
            showChanges: $request->boolean('show_changes'),
            showAllowances: $request->query('show_allowances') === null ? true : $request->boolean('show_allowances'),
        );

        return response($result['binary'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /** Mirrors App\Livewire\Estimates\EstimateShow::export_csv() (an XLSX despite the method's name). */
    public function xlsxSigned(Request $request, int $estimate)
    {
        $found = Estimate::withoutGlobalScopes()
            ->with(['estimate_sections.estimate_line_items.allowances', 'vendor', 'project.client'])
            ->find($estimate);

        abort_unless($found && $found->vendor, 404);

        $showAllowances = $request->query('show_allowances') === null ? true : $request->boolean('show_allowances');

        $result = EstimateDocumentGenerator::generateXlsx($found, $showAllowances);

        return response($result['binary'])
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /** Mirrors App\Livewire\Estimates\EstimateShow::downloadSignedContract(). */
    public function signedContract(int $estimate)
    {
        $found = Estimate::withoutGlobalScopes()->find($estimate);

        abort_unless($found && $found->signed_contract_path, 404);
        abort_unless(Storage::disk('local')->exists($found->signed_contract_path), 404);

        $filename = 'Signed Contract - Estimate '.$found->number.'.pdf';

        return Storage::disk('local')->download($found->signed_contract_path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
