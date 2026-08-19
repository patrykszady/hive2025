<?php

namespace App\Support;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Keep only specific pages out of a PDF binary — the counterpart to
 * PdfMerger. Built for WaiverScanIngest: a vendor's scanner routinely glues
 * an unrelated page (their own invoice, a cover sheet) onto the returned
 * waiver scan, and that page must never end up in the stored legal document.
 *
 * Pure in-memory (StreamReader::createByString) — no temp files.
 */
class PdfPageExtractor
{
    /**
     * Total page count of a PDF binary, or null if it can't be parsed.
     */
    public static function pageCount(string $binary): ?int
    {
        if (! str_starts_with($binary, '%PDF')) {
            return null;
        }

        try {
            return (new Fpdi())->setSourceFile(StreamReader::createByString($binary));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A new PDF binary containing only the given 1-indexed page numbers, in
     * the order given. Returns null on any failure (unparseable source, a
     * page number out of range) — callers must fall back to the original
     * binary rather than risk storing a corrupt or wrongly-trimmed document.
     *
     * @param  array<int, int>  $pages
     */
    public static function extractPages(string $binary, array $pages): ?string
    {
        if ($pages === [] || ! str_starts_with($binary, '%PDF')) {
            return null;
        }

        try {
            $pdf = new Fpdi();
            $totalPages = $pdf->setSourceFile(StreamReader::createByString($binary));

            foreach ($pages as $pageNumber) {
                if ($pageNumber < 1 || $pageNumber > $totalPages) {
                    return null;
                }

                $templateId = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage(
                    $size['height'] > $size['width'] ? 'P' : 'L',
                    [$size['width'], $size['height']],
                );
                $pdf->useTemplate($templateId);
            }

            $output = $pdf->Output('S');

            return str_starts_with($output, '%PDF') ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }
}
