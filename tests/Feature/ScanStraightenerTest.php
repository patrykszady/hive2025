<?php

use App\Support\PdfPageExtractor;
use App\Support\ScanStraightener;

/**
 * Wired into WaiverScanIngest right before a returned scan is archived. These
 * exercise the real `gs`/`convert` pipeline (no fakes) — slower than a typical
 * unit test, but the whole point is the actual rasterize/measure/correct
 * round trip, not a mock of it.
 */
function fpdfPageWithLines(int $lines = 40): \FPDF
{
    // Enough horizontal rule structure for ImageMagick's deskew detector to
    // have something to lock onto — a near-blank page gives it nothing.
    $pdf = new \FPDF();
    $pdf->AddPage();
    for ($i = 0; $i < $lines; $i++) {
        $y = 10 + $i * 6;
        $pdf->Line(10, $y, 200, $y);
    }

    return $pdf;
}

it('returns non-PDF input unchanged', function () {
    expect(ScanStraightener::straighten('not a pdf at all'))->toBe('not a pdf at all')
        ->and(ScanStraightener::straighten(''))->toBe('');
});

it('leaves an already-square page byte-identical', function () {
    $binary = fpdfPageWithLines()->Output('S');

    expect(ScanStraightener::straighten($binary))->toBe($binary);
});

it('corrects a genuinely crooked page, keeping it a valid same-page-count PDF', function () {
    $straightPdfPath = tempnam(sys_get_temp_dir(), 'scan_straight_').'.pdf';
    file_put_contents($straightPdfPath, fpdfPageWithLines()->Output('S'));

    $rasterPath = tempnam(sys_get_temp_dir(), 'scan_raster_').'.jpg';
    (new Symfony\Component\Process\Process(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=jpeg', '-r300', '-o', $rasterPath, $straightPdfPath]))->run();

    $rotatedPath = tempnam(sys_get_temp_dir(), 'scan_rotated_').'.jpg';
    (new Symfony\Component\Process\Process(['convert', $rasterPath, '-background', 'white', '-rotate', '6', '+repage', $rotatedPath]))->run();

    $crookedPdf = new setasign\Fpdi\Fpdi();
    $crookedPdf->AddPage();
    [$w, $h] = getimagesize($rotatedPath);
    $crookedPdf->Image($rotatedPath, 0, 0, $w / 300 * 25.4, $h / 300 * 25.4, 'JPG');
    $crookedBinary = $crookedPdf->Output('S');

    @unlink($straightPdfPath);
    @unlink($rasterPath);
    @unlink($rotatedPath);

    $corrected = ScanStraightener::straighten($crookedBinary);

    expect($corrected)->not->toBe($crookedBinary)
        ->and(str_starts_with($corrected, '%PDF'))->toBeTrue()
        ->and(PdfPageExtractor::pageCount($corrected))->toBe(PdfPageExtractor::pageCount($crookedBinary));
});

it('keeps every page when straightening a multi-page document', function () {
    $pdf = new \FPDF();
    for ($page = 0; $page < 3; $page++) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Page {$page}");
    }

    $binary = $pdf->Output('S');
    $result = ScanStraightener::straighten($binary);

    expect(PdfPageExtractor::pageCount($result))->toBe(3);
});
