<?php

namespace App\Support\Insurance;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The text of a PDF, read with Ghostscript (installed on prod and dev; no
 * new dependency). A scanned page has no text layer and comes back empty:
 * a notice that is only a scan is recognised by its email instead.
 */
class PdfText
{
    /** Notices are a page or two; the first pages are enough. */
    public const MAX_PAGES = 5;

    public function extract(string $pdf): string
    {
        if (! str_starts_with(ltrim($pdf), '%PDF')) {
            return '';
        }
        $path = tempnam(sys_get_temp_dir(), 'notice_').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $result = Process::timeout(30)->run([
                'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-dLastPage='.self::MAX_PAGES,
                '-sDEVICE=txtwrite', '-sOutputFile=-', $path,
            ]);
            if (! $result->successful()) {
                Log::channel('vendor_docs')->warning('PDF text could not be read', ['error' => trim($result->errorOutput())]);

                return '';
            }

            return $result->output();
        } finally {
            @unlink($path);
        }
    }
}
