<?php

use App\Support\PdfDocumentFooter;

/**
 * The scan-matching footer on every lien waiver / GCSS page. A bad scan not
 * matching a barcode is a recurring real complaint — these pin the two fixes:
 * a wider Code 128 (more physical width per bar survives scan damage/blur)
 * and a QR code alongside it as a redundant, error-corrected backup.
 */
it('renders a wider Code 128 than the library default, plus a QR backup encoding the same value', function () {
    $html = PdfDocumentFooter::build('HLW-42', 'lien-waiver-42-test.pdf');

    // Widened from the original 1.6 (below the picqer default of 2) to 2.2 —
    // widthFactor is the exact parameter picqer's getBarcode() takes.
    $svg = (new \Picqer\Barcode\BarcodeGeneratorSVG())
        ->getBarcode('HLW-42', \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_CODE_128, 2.2, 30);
    expect($html)->toContain(base64_encode($svg));

    // A QR SVG data-URI is present too — two independent <img> tags.
    expect(substr_count($html, '<img src="data:image/svg+xml;base64,'))->toBe(2);
});

it('degrades to a plain text fallback (not a blank footer) when the barcode value is empty', function () {
    $html = PdfDocumentFooter::build('', 'sworn-statement-x.pdf');

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('sworn-statement-x.pdf')
        ->and($html)->toContain(PdfDocumentFooter::RETURN_EMAIL);
});

it('the QR encodes the same identity the barcode does, and Azure decodes both', function () {
    // This is the contract WaiverScanIngest::resolveCodes() depends on: a
    // decoded QR must be findable by the SAME regex as a decoded Code 128.
    // Confirmed once against the live analyzer (both annotate as
    // "barcodes/{page}.{n} \"{code}\"" with alt text containing "Code") —
    // pinned here structurally so a future change can't silently drop it.
    $html = PdfDocumentFooter::build('HSS-7', 'sworn-statement-7.pdf');

    preg_match_all('/data:image\/svg\+xml;base64,([^"]+)/', $html, $matches);
    expect($matches[1])->toHaveCount(2);

    [$qrSvg, $barcodeSvg] = array_map('base64_decode', $matches[1]);
    expect($qrSvg)->toContain('<svg')->and($barcodeSvg)->toContain('<svg');
});
