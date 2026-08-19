<?php

namespace App\Support;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * The shared per-page footer for returnable documents (lien waivers, GCSS):
 * a zinc-bordered card floated left holding the scan-matching barcode(s) and
 * the filename, next to the "email it back" instruction. Rendered by
 * Chromium's footer template, so it repeats on every page.
 *
 * Two redundant encodings of the same identity ("HLW-{id}" / "HSS-{id}"):
 * a Code 128 (the original, unbroken by earlier ingests) and a QR code next
 * to it. A 1D symbol like Code 128 has zero error correction — one merged or
 * missing bar from a crumpled corner or a phone scanner's aggressive B/W
 * threshold breaks the whole read. A QR at ErrorCorrectionLevel::High
 * tolerates roughly 30% of the symbol being damaged or obscured, so it
 * survives exactly the bad-scan cases the barcode alone doesn't.
 * WaiverScanIngest::resolveCodes() needs no change to read it — Azure
 * Content Understanding annotates a decoded QR the same way it does a
 * barcode ("![QRCode](barcodes/{page}.{n} "HLW-11")"), and the existing
 * match on the substring "Code" in the alt text already covers "QRCode".
 */
class PdfDocumentFooter
{
    public const RETURN_EMAIL = 'waivers@hive.contractors';

    public static function build(string $barcodeValue, string $filename, bool $withPageCounter = false): string
    {
        $filenameHtml = e($filename);

        $barcodeImg = '';
        $qrImg = '';

        if ($barcodeValue !== '') {
            try {
                // Widened from the original 1.6: each bar's absolute printed
                // width is what determines how much scan damage/blur it
                // survives, and 1.6 sat BELOW the library's own default of 2.
                // Height is unchanged; the <img> below sets height only, so
                // the wider intrinsic aspect ratio grows the physical width
                // on the printed page without any other layout change.
                $svg = (new \Picqer\Barcode\BarcodeGeneratorSVG())
                    ->getBarcode($barcodeValue, \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_CODE_128, 2.2, 30);
                $barcodeImg = '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="height: 6.5mm; display:block;" alt="">';
            } catch (\Throwable) {
                // No barcode library / bad input — print the code so the
                // identity never disappears entirely.
                $barcodeImg = '<div style="font-size: 7px; color: #52525b; letter-spacing: 0.08em;">' . e($barcodeValue) . '</div>';
            }

            try {
                $qr = new QrCode(
                    data: $barcodeValue,
                    errorCorrectionLevel: ErrorCorrectionLevel::High,
                    size: 120,
                    margin: 4,
                );
                $qrSvg = (new SvgWriter())->write($qr)->getString();
                $qrImg = '<img src="data:image/svg+xml;base64,' . base64_encode($qrSvg) . '" style="height: 9mm; width: 9mm; display:block;" alt="">';
            } catch (\Throwable) {
                // The Code 128 (or its text fallback) above still carries the
                // identity on its own — the QR is a backup, not the only copy.
            }
        }

        $returnEmail = e(self::RETURN_EMAIL);
        $pageCounter = $withPageCounter
            ? '<div style="font-size:7px; color:#000; white-space:nowrap;">Page <span class="pageNumber"></span> of <span class="totalPages"></span> Pages</div>'
            : '';

        return <<<HTML
<div style="width:100%; font-family:Arial, sans-serif; padding:0 12mm 1mm; box-sizing:border-box;">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px;">
        <div style="border:1px solid #d4d4d8; background-color:#fafafa; border-radius:8px; padding:6px 10px; text-align:left;">
            <div style="font-size:10px; color:#27272a; margin-bottom:4px;">Please email the signed copy back to <strong>{$returnEmail}</strong> ASAP.</div>
            <div style="display:flex; align-items:center; gap:8px;">
                {$qrImg}
                {$barcodeImg}
                <div style="font-size:6.5px; color:#71717a; text-align:left; max-width:56mm;">{$filenameHtml}</div>
            </div>
        </div>
        {$pageCounter}
    </div>
</div>
HTML;
    }
}
