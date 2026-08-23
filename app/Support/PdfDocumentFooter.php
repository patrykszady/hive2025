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

    public static function build(string $barcodeValue, string $filename, bool $withPageCounter = false, string $revisionLabel = ''): string
    {
        // Revision marker for the scan ingest: the barcode says WHICH waiver,
        // this says which generation of it. Only present past revision 1.
        $revisionHtml = $revisionLabel !== ''
            ? '<div style="font-size: 8px; font-weight: 700; color: #b91c1c; letter-spacing: 0.08em;">' . e($revisionLabel) . '</div>'
            : '';

        // $filename is no longer printed. It was ~100 characters wide and
        // consumed the bottom-right of the page — exactly where a notary signs
        // and stamps — and the printed id below matches far more reliably in a
        // fraction of the width. The parameter is kept so both callers continue
        // to work unchanged.

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
                // Taller bars, not just wider ones: a 1D symbol is decoded
                // along a scan line, so height is what buys tolerance to the
                // skew and page-curl that a phone photo of a signed page
                // always has. 6.5mm gave a decoder barely any clean line to
                // find on a warped scan.
                $barcodeImg = '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="height: 5mm; display:block;" alt="">';
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
                    // Kept at the spec's 4-module quiet zone. Shrinking it
                    // would buy module size but decoders are entitled to
                    // reject a symbol without it.
                    margin: 4,
                );
                $qrSvg = (new SvgWriter())->write($qr)->getString();
                // "HLW-17" fits QR version 1 — a 21x21 matrix. At the previous
                // 9mm box (4-module quiet zone included) each module printed at
                // ~0.36mm, which needs roughly 180 DPI to survive; 14mm puts it
                // near 0.55mm and holds up down to about 120 DPI, which is the
                // range faxed and threshold-crushed scans actually land in.
                $qrImg = '<img src="data:image/svg+xml;base64,' . base64_encode($qrSvg) . '" style="height: 10mm; width: 10mm; display:block;" alt="">';
            } catch (\Throwable) {
                // The Code 128 (or its text fallback) above still carries the
                // identity on its own — the QR is a backup, not the only copy.
            }
        }

        // Third, symbol-free channel. Both encodings above are all-or-nothing
        // under damage; plain characters degrade gracefully and OCR reads them
        // long after a symbol stops decoding. Printed bare — the id's own shape
        // ("HLW-16") is distinctive enough, and the analyzer is pointed at this
        // exact spot in the card rather than hunting the page for it.
        $docIdHtml = $barcodeValue !== ''
            ? '<div style="font-size:10px; font-weight:700; color:#27272a; letter-spacing:0.06em; white-space:nowrap;">' . e($barcodeValue) . '</div>'
            : '';

        $returnEmail = e(self::RETURN_EMAIL);
        $pageCounter = $withPageCounter
            ? '<div style="font-size:7px; color:#000; white-space:nowrap;">Page <span class="pageNumber"></span> of <span class="totalPages"></span> Pages</div>'
            : '';

        return <<<HTML
<div style="width:100%; font-family:Arial, sans-serif; padding:0 12mm 0; box-sizing:border-box;">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px;">
        <!-- The card is sized to its contents and stays left: the bottom-right of
             the page is reserved for the notary's signature and seal, which are
             applied by hand after printing. -->
        <div style="border:1px solid #d4d4d8; background-color:#fafafa; border-radius:8px; padding:3px 7px; text-align:left;">
            <div style="font-size:9px; line-height:1.2; color:#27272a; margin-bottom:2px; white-space:nowrap;">Email signed copy to <strong>{$returnEmail}</strong></div>
            <div style="display:flex; align-items:center; gap:6px;">
                {$qrImg}
                <div style="display:flex; flex-direction:column; gap:2px;">
                    {$barcodeImg}
                    {$docIdHtml}
                </div>
                {$revisionHtml}
            </div>
        </div>
        {$pageCounter}
    </div>
</div>
HTML;
    }
}
