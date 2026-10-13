<?php

namespace App\Http\Controllers;

use App\Models\AuditRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an audit's built package from its signed link (the "audit ready"
 * email): no sign-in, the signature is the key, so the email can be
 * forwarded to the auditor as it is.
 */
class AuditPackageController extends Controller
{
    public function download(AuditRequest $audit, string $file): StreamedResponse
    {
        $path = $file === 'pdf' ? $audit->pdf_path : $audit->xlsx_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'That file is not built yet.');

        return Storage::disk('local')->download($path, basename($path));
    }
}
