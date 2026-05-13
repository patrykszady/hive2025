<?php

namespace App\Http\Controllers;

use App\Models\LienWaiver;
use App\Support\LienWaiverDocumentGenerator;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LienWaiverController extends Controller
{
    /**
     * Stream the latest PDF (signed if available, otherwise draft) for a
     * LienWaiver to the authorized user.
     */
    public function download(LienWaiver $lienWaiver): Response|BinaryFileResponse
    {
        $path = $lienWaiver->signed_path ?: $lienWaiver->draft_path;

        if ($path) {
            $absolute = \Storage::disk('files')->path($path);

            if (is_file($absolute)) {
                return response()->download(
                    $absolute,
                    basename($path),
                    ['Content-Type' => 'application/pdf']
                );
            }
        }

        // No persisted file yet — render on the fly.
        try {
            $doc = LienWaiverDocumentGenerator::generate($lienWaiver);
        } catch (\Throwable) {
            throw new NotFoundHttpException('Unable to render lien waiver PDF.');
        }

        return response($doc['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $doc['filename'] . '"',
        ]);
    }
}
