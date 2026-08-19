<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Straighten a crooked scanned PDF — a phone held a few degrees off square
 * when the vendor photographed their wet-signed waiver, and the result reads
 * worse and is more likely to defeat both barcode AND OCR field extraction.
 *
 * No PHP package does this well: Packagist has nothing real for "deskew" (the
 * few hits are unrelated), and the PHP `imagick` extension isn't installed
 * here (only `gd`, which has no deskew primitive at all). What every scanning
 * app actually uses under the hood is the classic Hough-transform-family
 * technique ImageMagick's CLI already ships — `convert -deskew`, and it's
 * already on this box (PdfMerger already depends on its sibling `gs` for the
 * same reason: shell out to the standard tool rather than reinvent it in
 * pure PHP).
 *
 * Pipeline per page: rasterize via Ghostscript (already a dependency) ->
 * measure ImageMagick's own detected skew angle -> only rebuild the page
 * from a corrected raster when the angle clears a real-skew threshold, so a
 * page that was already square is returned byte-identical rather than paying
 * a pointless re-encode. Any missing binary, unparseable PDF, or mid-pipeline
 * failure degrades to returning the input untouched — the same grace every
 * other pipeline stage here extends (FaceBlur, PdfMerger).
 */
class ScanStraightener
{
    /**
     * Degrees below which a page is left alone. ImageMagick's own detector
     * reads ~0.6-0.7° on scans that are visually dead straight (JPEG/print
     * noise, not real skew) — measured against a real returned waiver scan —
     * so this sits comfortably above that floor.
     */
    protected const SKEW_THRESHOLD_DEGREES = 1.25;

    protected const RASTER_DPI = 300;

    /**
     * @return string the straightened PDF binary, or $binary unchanged when
     *                 nothing needed correcting or the tools aren't available
     */
    public static function straighten(string $binary): string
    {
        if (! str_starts_with($binary, '%PDF')) {
            return $binary;
        }

        $gs = self::findBinary('gs');
        $convert = self::findBinary('convert');

        if ($gs === null || $convert === null) {
            Log::channel('waiver_scans')->warning('Scan straighten skipped — gs or convert not on PATH.', [
                'gs' => $gs !== null, 'convert' => $convert !== null,
            ]);

            return $binary;
        }

        $tmpDir = storage_path('app/tmp/scan-straighten/' . uniqid());

        try {
            if (! @mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
                return $binary;
            }

            $pageCount = PdfPageExtractor::pageCount($binary);
            if ($pageCount === null || $pageCount < 1) {
                return $binary;
            }

            $sourcePath = $tmpDir . '/source.pdf';
            file_put_contents($sourcePath, $binary);

            $rasterPattern = $tmpDir . '/page-%d.jpg';
            $raster = new Process([
                $gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=jpeg', '-dJPEGQ=92', '-r' . self::RASTER_DPI,
                '-o', $rasterPattern, $sourcePath,
            ]);
            $raster->setTimeout(120);
            $raster->run();

            if (! $raster->isSuccessful()) {
                Log::channel('waiver_scans')->warning('Scan straighten: rasterizing failed — keeping the original.', [
                    'error' => substr($raster->getErrorOutput(), -500),
                ]);

                return $binary;
            }

            $anyCorrected = false;
            $pageImages = [];

            for ($page = 1; $page <= $pageCount; $page++) {
                $rasterPath = $tmpDir . "/page-{$page}.jpg";

                if (! is_file($rasterPath)) {
                    // Ghostscript numbers pages 1..N contiguously; a gap means
                    // something went wrong — bail out to the safe fallback
                    // rather than assemble a PDF missing a page.
                    return $binary;
                }

                $angle = self::detectSkewAngle($convert, $rasterPath);

                if ($angle !== null && abs($angle) >= self::SKEW_THRESHOLD_DEGREES) {
                    $correctedPath = $tmpDir . "/page-{$page}-corrected.jpg";

                    $deskew = new Process([
                        $convert, $rasterPath,
                        '-deskew', '40%', '-background', 'white', '+repage',
                        $correctedPath,
                    ]);
                    $deskew->setTimeout(60);
                    $deskew->run();

                    if ($deskew->isSuccessful() && is_file($correctedPath)) {
                        $pageImages[] = $correctedPath;
                        $anyCorrected = true;

                        continue;
                    }
                }

                $pageImages[] = $rasterPath;
            }

            // Nothing was actually crooked — the original binary (its own
            // native encoding, whatever that was) is strictly preferable to
            // a pointless rasterize-and-reassemble that only adds JPEG
            // generation loss for no visual benefit.
            if (! $anyCorrected) {
                return $binary;
            }

            $rebuilt = self::assemblePdf($pageImages);

            if ($rebuilt === null) {
                Log::channel('waiver_scans')->warning('Scan straighten: rebuilding the PDF failed — keeping the original.');

                return $binary;
            }

            Log::channel('waiver_scans')->info('Scan straighten: corrected page skew.', ['pages' => $pageCount]);

            return $rebuilt;
        } catch (Throwable $e) {
            Log::channel('waiver_scans')->warning('Scan straighten: unexpected failure — keeping the original.', [
                'error' => $e->getMessage(),
            ]);

            return $binary;
        } finally {
            self::removeDirectory($tmpDir);
        }
    }

    /**
     * ImageMagick's own detected rotation, in degrees — the same `-deskew`
     * pass that would correct the image, run once first just to read the
     * `deskew:angle` artifact it sets, so a straight page never pays for a
     * correction it doesn't need.
     */
    protected static function detectSkewAngle(string $convert, string $imagePath): ?float
    {
        $probe = new Process([
            $convert, $imagePath, '-deskew', '40%', '-format', '%[deskew:angle]', 'info:',
        ]);
        $probe->setTimeout(60);
        $probe->run();

        if (! $probe->isSuccessful()) {
            return null;
        }

        $value = trim($probe->getOutput());

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * One full-page image per page, in order, via FPDI/FPDF (already a
     * dependency — PdfPageExtractor uses the same pair). Each page is sized
     * to match the source image's own pixel dimensions at RASTER_DPI, so the
     * output keeps the source's page proportions rather than forcing Letter.
     */
    protected static function assemblePdf(array $imagePaths): ?string
    {
        try {
            $pdf = new Fpdi();

            foreach ($imagePaths as $imagePath) {
                [$pxWidth, $pxHeight] = getimagesize($imagePath);
                $mmWidth = $pxWidth / self::RASTER_DPI * 25.4;
                $mmHeight = $pxHeight / self::RASTER_DPI * 25.4;

                $pdf->AddPage($mmHeight > $mmWidth ? 'P' : 'L', [$mmWidth, $mmHeight]);
                $pdf->Image($imagePath, 0, 0, $mmWidth, $mmHeight, 'JPG');
            }

            $output = $pdf->Output('S');

            return str_starts_with($output, '%PDF') ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function findBinary(string $name): ?string
    {
        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        return $resolved !== '' ? $resolved : null;
    }

    protected static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
