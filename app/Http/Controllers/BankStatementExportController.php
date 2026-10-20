<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A signed, no-session bank statement PDF for the Hive Contractors app:
 * see routes/web.php's banks.statement.signed for the route this backs.
 * Minted by GET /api/app/v1/banks/{id}/statement-url (see
 * Api\App\V1\BankController::statementUrl()), same pattern as
 * SheetExportController: the PDF was already fetched from Plaid and
 * rendered once, while authenticated, and only stored under
 * storage/app/exports/bank-statements for this one-time, time-limited
 * fetch — the `signed` middleware alone is the gate.
 */
class BankStatementExportController extends Controller
{
    public function signed(Request $request, string $token)
    {
        $path = "exports/bank-statements/{$token}.pdf";

        abort_unless(Storage::disk('local')->exists($path), 404);

        $filename = (string) $request->query('filename', 'bank-statement.pdf');

        return Storage::disk('local')->download($path, $filename, ['Content-Type' => 'application/pdf']);
    }
}
