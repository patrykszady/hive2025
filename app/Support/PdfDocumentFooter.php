<?php

namespace App\Support;

/**
 * The shared per-page footer for returnable documents (lien waivers, GCSS):
 * a zinc-bordered card floated left holding the scan-matching Code 128
 * barcode and the filename, next to the "email it back" instruction.
 * Rendered by Chromium's footer template, so it repeats on every page.
 */
class PdfDocumentFooter
{
    public const RETURN_EMAIL = 'waivers@hive.contractors';

    public static function build(string $barcodeValue, string $filename, bool $withPageCounter = false): string
    {
        $filenameHtml = e($filename);

        $barcodeImg = '';
        if ($barcodeValue !== '') {
            try {
                $svg = (new \Picqer\Barcode\BarcodeGeneratorSVG())
                    ->getBarcode($barcodeValue, \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_CODE_128, 1.6, 30);
                $barcodeImg = '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="height: 6.5mm; display:block;" alt="">';
            } catch (\Throwable) {
                // No barcode library / bad input — print the code so the
                // identity never disappears entirely.
                $barcodeImg = '<div style="font-size: 7px; color: #52525b; letter-spacing: 0.08em;">' . e($barcodeValue) . '</div>';
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
            <div style="display:flex; align-items:center; gap:10px;">
                {$barcodeImg}
                <div style="font-size:6.5px; color:#71717a; text-align:left; max-width:66mm;">{$filenameHtml}</div>
            </div>
        </div>
        {$pageCounter}
    </div>
</div>
HTML;
    }
}
