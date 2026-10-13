<?php

namespace App\Http\Controllers;

use App\Models\AuditRequest;
use App\Models\VendorDoc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an audit's built package to an admin of the company it belongs
 * to; the "audit ready" email links here.
 */
class AuditPackageController extends Controller
{
    public function download(Request $request, AuditRequest $audit, string $file): StreamedResponse
    {
        $this->authorize('viewAny', VendorDoc::class);
        abort_unless((int) $request->user()->vendor?->id === (int) $audit->belongs_to_vendor_id, 403);

        $path = $file === 'pdf' ? $audit->pdf_path : $audit->xlsx_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'That file is not built yet.');

        return Storage::disk('local')->download($path, basename($path));
    }
}
