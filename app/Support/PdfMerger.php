<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class PdfMerger
{
    /**
     * Merge PDF binaries into one document with Ghostscript. Returns the
     * merged binary, the single input when only one valid PDF was given, or
     * null when nothing could be merged (caller falls back to separates).
     *
     * @param array<int, string> $binaries
     */
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
