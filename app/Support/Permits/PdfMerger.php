<?php

namespace App\Support\Permits;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Several PDFs into one, in order, with Ghostscript (on dev and prod; FPDI's
 * free parser refuses the compressed PDFs plan software writes).
 */
class PdfMerger
{
    /** @param  list<string>  $inputPaths absolute paths, in packet order */
    public function merge(array $inputPaths, string $outputPath): void
    {
        $inputPaths = array_values(array_filter($inputPaths, fn ($path) => is_string($path) && is_file($path)));

        if ($inputPaths === []) {
            throw new RuntimeException('Nothing to merge.');
        }

        $process = new Process(array_merge([
            (string) config('services.ghostscript.binary', 'gs'),
            '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dPDFSETTINGS=/prepress',
            '-dAutoRotatePages=/None',
            '-sOutputFile='.$outputPath,
        ], $inputPaths));
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Merging the permit packet failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
