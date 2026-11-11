<?php

namespace App\Services\Expenses;

use App\Services\ContentUnderstandingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Looks at a scanned receipt for the office's client-reimbursement mark: a
 * hand-written R, normally inside a hand-drawn circle, at the top of the
 * page beside the job number.
 *
 * The receipt reader (the Azure document analyzer) answers from the text it
 * transcribed and so misses the mark whenever the transcription dropped the
 * R — about one in four (measured 2026-11-11). This shows the picture to an
 * Azure IMAGE analyzer instead (services.azure_cu.analyzer_id_receipt_mark,
 * defined by `content-understanding:sync-analyzer receipt_mark`): the top of
 * a PDF's first page, or all of a photo, answered true, false or unknown.
 */
class ScannedReceiptMarkReader
{
    /** How much of the first page is shown, from the top. */
    public const TOP_SHARE = 0.34;

    protected const DPI = 110;

    public function __construct(protected ContentUnderstandingService $contentUnderstanding) {}

    /**
     * @return array{mark: 'true'|'false'|'unknown', handwriting: string}
     */
    public function read(string $absolutePath): array
    {
        $png = $this->topOfFirstPage($absolutePath) ?? throw new RuntimeException('scanned_receipt_mark_unrenderable');

        $raw = $this->contentUnderstanding->analyzeBinary($png, (string) config('services.azure_cu.analyzer_id_receipt_mark'), 'vendor_docs');
        $fields = $raw['result']['contents'][0]['fields'] ?? [];

        return [
            'mark' => ReceiptReimbursementMark::answerFrom($fields['ClientReimbursementMark'] ?? null),
            'handwriting' => trim((string) ($fields['Handwriting']['valueString'] ?? '')),
        ];
    }

    /**
     * The top strip of the first page as a PNG: where the office writes.
     */
    public function topOfFirstPage(string $absolutePath): ?string
    {
        $isPdf = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'pdf';
        $page = $isPdf
            ? $this->renderFirstPage($absolutePath)
            : (is_file($absolutePath) ? (string) file_get_contents($absolutePath) : null);

        $image = $page ? @imagecreatefromstring($page) : false;

        if (! $image) {
            return null;
        }

        if (! $isPdf) {
            // A phone photo: turn it upright, and show all of it — the
            // receipt can sit anywhere in the frame.
            $image = $this->scaledDown($this->upright($image, $absolutePath), 2000);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // A long till receipt: a third of it is plenty. A short one (or a
        // letter-size invoice) keeps at least a readable band.
        $strip = (int) min($height, max($height * self::TOP_SHARE, min($height, $width * 0.6)));
        $top = imagecrop($image, ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $strip]);

        if (! $top) {
            return null;
        }

        ob_start();
        imagepng($top);

        return (string) ob_get_clean();
    }

    /** A JPEG as the camera meant it: rotated per its EXIF orientation. */
    protected function upright(\GdImage $image, string $absolutePath): \GdImage
    {
        $orientation = function_exists('exif_read_data') ? (int) (@exif_read_data($absolutePath)['Orientation'] ?? 1) : 1;
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $degrees !== 0 ? (imagerotate($image, $degrees, 0) ?: $image) : $image;
    }

    protected function scaledDown(\GdImage $image, int $longestSide): \GdImage
    {
        $longest = max(imagesx($image), imagesy($image));

        return $longest > $longestSide
            ? (imagescale($image, (int) round(imagesx($image) * $longestSide / $longest)) ?: $image)
            : $image;
    }

    protected function renderFirstPage(string $absolutePath): ?string
    {
        $out = tempnam(sys_get_temp_dir(), 'receipt_top_').'.png';

        try {
            $result = Process::timeout(60)->run([
                'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pnggray', '-r'.self::DPI,
                '-dFirstPage=1', '-dLastPage=1', '-sOutputFile='.$out, $absolutePath,
            ]);

            if (! $result->successful() || ! is_file($out)) {
                Log::warning('Scanned receipt could not be rendered for its mark', ['file' => basename($absolutePath), 'error' => trim($result->errorOutput())]);

                return null;
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -4));
        }
    }
}
