<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class PdfMerger
{
    /**
     * Merge PDF binaries into one document. Returns the merged binary, the
     * single input when only one valid PDF was given, or null when nothing
     * could be merged (caller falls back to separates).
     *
     * FPDI first, Ghostscript second. FPDI ships with the app, so merging works
     * on any box without a system package — which matters because a missing `gs`
     * failed silently: the merge returned null and the caller quietly handed
     * back only the first document, so a draw package arrived as the sworn
     * statement alone with no error anywhere.
     *
     * Ghostscript stays as the fallback because it parses things FPDI's free
     * edition will not — most importantly PDFs using compressed object streams,
     * which a vendor's scanner or phone app can easily produce.
     *
     * Neither preserves AcroForm fields: merged output is flat. That is fine for
     * an archival bundle; individual downloads remain fillable.
     *
     * @param array<int, string> $binaries
     */
    /**
     * Pure-PHP merge. Page size and orientation are carried over per page, so a
     * landscape scan in a portrait package keeps its shape.
     *
     * @param  array<int, string>  $binaries
     */
    protected static function mergeWithFpdi(array $binaries): ?string
    {
        $temp = [];

        try {
            // Extends FPDF, not TCPDF — no header/footer hooks to disable.
            $pdf = new \setasign\Fpdi\Fpdi();

            foreach ($binaries as $binary) {
                $path = tempnam(sys_get_temp_dir(), 'mrg') . '.pdf';
                file_put_contents($path, $binary);
                $temp[] = $path;

                $pages = $pdf->setSourceFile($path);

                for ($page = 1; $page <= $pages; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            $out = $pdf->Output('S');

            return str_starts_with($out, '%PDF') ? $out : null;
        } catch (\Throwable) {
            // Most often a compressed object stream, which the free FPDI cannot
            // read. Ghostscript can, so let the caller fall through to it.
            return null;
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }
    }

    public static function merge(array $binaries): ?string
    {
        $binaries = array_values(array_filter(
            $binaries,
            static fn ($binary) => is_string($binary) && str_starts_with($binary, '%PDF'),
        ));

        if (empty($binaries)) {
            return null;
        }

        if (count($binaries) === 1) {
            return $binaries[0];
        }

        if (($merged = self::mergeWithFpdi($binaries)) !== null) {
            return $merged;
        }

        $tmpDir = storage_path('app/tmp/pdf-merge');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $parts = [];
        foreach ($binaries as $i => $binary) {
            $path = $tmpDir . '/part-' . uniqid() . "-{$i}.pdf";
            file_put_contents($path, $binary);
            $parts[] = $path;
        }

        $merged = $tmpDir . '/merged-' . uniqid() . '.pdf';

        try {
            $process = new Process(array_merge(
                ['gs', '-dBATCH', '-dNOPAUSE', '-q', '-sDEVICE=pdfwrite', '-sOutputFile=' . $merged],
                $parts,
            ));
            $process->setTimeout(120);
            $process->run();

            if ($process->isSuccessful() && is_file($merged)) {
                $binary = (string) file_get_contents($merged);

                return str_starts_with($binary, '%PDF') ? $binary : null;
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            foreach ($parts as $path) {
                @unlink($path);
            }
            @unlink($merged);
        }
    }
}
