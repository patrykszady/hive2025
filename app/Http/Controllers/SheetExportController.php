<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Signed, no-session P&L report downloads — see routes/web.php's
 * sheets.export.csv.signed/sheets.export.pdf.signed for the routes these
 * back. Minted by GET /api/app/v1/sheets/export/csv-url and export/pdf-url
 * (see Api\App\V1\SheetController), the same pattern as
 * estimates.xlsx.signed: the `signed` middleware alone is the gate.
 *
 * Unlike those estimate/check/receipt routes, this never re-runs a query:
 * the file was already rendered, while authenticated and correctly
 * tenant-scoped, by SheetController's export endpoint, and only stored
 * under storage/app/exports/sheets for this one-time, time-limited fetch
 * (see that controller's docblock for why — the report's own queries rely
 * on global scopes that only filter by company when there's a signed-in
 * user).
 */
class SheetExportController extends Controller
{
    public function csvSigned(Request $request, string $token)
    {
        return $this->stream($request, $token, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function pdfSigned(Request $request, string $token)
    {
        return $this->stream($request, $token, 'pdf', 'application/pdf');
    }

    private function stream(Request $request, string $token, string $extension, string $contentType)
    {
        $path = "exports/sheets/{$token}.{$extension}";

        abort_unless(Storage::disk('local')->exists($path), 404);

        $filename = (string) $request->query('filename', "profit-and-loss.{$extension}");

        return Storage::disk('local')->download($path, $filename, ['Content-Type' => $contentType]);
    }
}
