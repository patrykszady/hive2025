<?php

namespace App\Services;

use App\Support\Insurance\PdfText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The front of a check cut out of the bank's single-check PDF, the one
 * Citibank's account dashboard saves for a cleared check: a details row
 * (account, check #, post date, amount), the front, the back, a security
 * tip. Statements caption each check BELOW its image and are cropped by
 * `cu:extract-checks`; this layout has the details above and the back
 * below, so the front is found by its own shape instead: the first block of
 * ink taller than MIN_FRONT_INCHES, ending at the white gap before the back.
 *
 * Ghostscript renders the page and reads its text; GD crops. Both are on
 * prod and dev already.
 */
class CheckFrontCropper
{
    public const DPI = 200;

    /** The front is at least this tall; the details row and the tip are lines of text. */
    public const MIN_FRONT_INCHES = 1.5;

    /** White bands thinner than this stay inside a block (a check's own blank rows). */
    public const BRIDGE_INCHES = 0.08;

    public const PAD_INCHES = 0.05;

    /**
     * A check front is about 2.2 times as wide as it is tall. A block taller
     * than width / this is the front with the back run into it (check 2627's
     * PDF has no white gap between them), and is cut at the front's bottom edge.
     */
    public const MAX_FRONT_HEIGHT_RATIO = 1.6;

    public function __construct(protected PdfText $pdfText) {}

    /**
     * @return array{png: string, details: array{account: ?string, check_number: ?int, posted_on: ?string, amount: ?float}}|null
     */
    public function fromPdf(string $pdf): ?array
    {
        $page = $this->renderFirstPage($pdf);
        $front = $page === null ? null : $this->cropFront($page);
        if ($front === null) {
            return null;
        }

        return ['png' => $front, 'details' => self::parseDetails($this->pdfText->extract($pdf))];
    }

    /**
     * The details row: "Business Checking-4903   2470   Sep 02, 2025   -$2,700.00".
     *
     * @return array{account: ?string, check_number: ?int, posted_on: ?string, amount: ?float}
     */
    public static function parseDetails(string $text): array
    {
        $details = ['account' => null, 'check_number' => null, 'posted_on' => null, 'amount' => null];
        $flat = (string) preg_replace('/\s+/', ' ', $text);
        if (! preg_match('/-(\d{3,})\s+(\d{1,9})\s+((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{1,2}, \d{4})\s+-?\$\s?([\d,]+\.\d{2})/', $flat, $m)) {
            return $details;
        }

        try {
            $postedOn = Carbon::parse($m[3])->toDateString();
        } catch (\Throwable) {
            $postedOn = null;
        }

        return [
            'account' => $m[1],
            'check_number' => (int) $m[2],
            'posted_on' => $postedOn,
            'amount' => (float) str_replace(',', '', $m[4]),
        ];
    }

    /** The front of the check on a rendered page, as PNG bytes; null when no block is tall enough. */
    public function cropFront(string $png): ?string
    {
        $image = @imagecreatefromstring($png);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $inked = function (int $x, int $y) use ($image): bool {
            $rgb = imagecolorat($image, $x, $y);

            return ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3 < 160;
        };

        $rowHasInk = [];
        for ($y = 0; $y < $height; $y++) {
            $rowHasInk[$y] = false;
            for ($x = 0; $x < $width; $x += 2) {
                if ($inked($x, $y)) {
                    $rowHasInk[$y] = true;
                    break;
                }
            }
        }

        $front = $this->firstTallBlock($rowHasInk);
        if ($front === null) {
            return null;
        }
        [$top, $bottom] = $front;
        [$left, $right] = $this->inkColumns($inked, $width, $top, $bottom);

        if (($bottom - $top) * self::MAX_FRONT_HEIGHT_RATIO > ($right - $left)) {
            $bottom = $this->frontBottomEdge($inked, $top, $bottom, $left, $right);
            [$left, $right] = $this->inkColumns($inked, $width, $top, $bottom);
        }

        $pad = (int) (self::PAD_INCHES * self::DPI);
        $box = ['x' => max(0, $left - $pad), 'y' => max(0, $top - $pad)];
        $box['width'] = min($width, $right + $pad) - $box['x'];
        $box['height'] = min($height, $bottom + $pad) - $box['y'];

        $crop = imagecrop($image, $box);
        if ($crop === false) {
            return null;
        }
        ob_start();
        imagepng($crop);

        return (string) ob_get_clean();
    }

    /**
     * The first and last columns with ink between two rows.
     *
     * @return array{0: int, 1: int}
     */
    protected function inkColumns(\Closure $inked, int $width, int $top, int $bottom): array
    {
        $left = $width;
        $right = 0;
        for ($x = 0; $x < $width; $x++) {
            for ($y = $top; $y <= $bottom; $y += 2) {
                if ($inked($x, $y)) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    break;
                }
            }
        }

        return [$left, $right];
    }

    /**
     * Where the front ends inside a block that also holds the back: the last
     * long horizontal line (the check's frame, over 55% of its width) in the
     * band a check's bottom edge falls in, else the emptiest row of that band.
     */
    protected function frontBottomEdge(\Closure $inked, int $top, int $bottom, int $left, int $right): int
    {
        $span = max(1, $right - $left);
        $from = $top + (int) ($span / 3.2);
        $to = min($bottom, $top + (int) ($span / 1.7));
        $lastLine = null;
        $emptiest = null;
        $fewest = PHP_INT_MAX;

        for ($y = $from; $y <= $to; $y++) {
            $longest = 0;
            $run = 0;
            $gap = 0;
            $count = 0;
            for ($x = $left; $x <= $right; $x++) {
                if ($inked($x, $y)) {
                    $count++;
                    $run += 1 + $gap;
                    $gap = 0;
                    $longest = max($longest, $run);
                } elseif ($run > 0 && ++$gap > 3) {
                    $run = 0;
                    $gap = 0;
                }
            }
            if ($longest > 0.55 * $span) {
                $lastLine = $y;
            }
            if ($count < $fewest) {
                $fewest = $count;
                $emptiest = $y;
            }
        }

        return $lastLine ?? $emptiest ?? $to;
    }

    /**
     * @param  array<int, bool>  $rowHasInk
     * @return array{0: int, 1: int}|null
     */
    protected function firstTallBlock(array $rowHasInk): ?array
    {
        $bridge = (int) (self::BRIDGE_INCHES * self::DPI);
        $minimum = (int) (self::MIN_FRONT_INCHES * self::DPI);
        $start = null;
        $end = null;
        $gap = 0;

        foreach ($rowHasInk as $y => $ink) {
            if ($ink) {
                $start ??= $y;
                $end = $y;
                $gap = 0;

                continue;
            }
            if ($start !== null && ++$gap > $bridge) {
                if ($end - $start >= $minimum) {
                    return [$start, $end];
                }
                $start = null;
                $gap = 0;
            }
        }

        return $start !== null && $end - $start >= $minimum ? [$start, $end] : null;
    }

    protected function renderFirstPage(string $pdf): ?string
    {
        if (! str_starts_with(ltrim($pdf), '%PDF')) {
            return null;
        }
        $in = tempnam(sys_get_temp_dir(), 'check_pdf_');
        $out = $in.'.png';
        file_put_contents($in, $pdf);

        try {
            $result = Process::timeout(60)->run([
                'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=png16m', '-r'.self::DPI,
                '-dFirstPage=1', '-dLastPage=1', '-sOutputFile='.$out, $in,
            ]);
            if (! $result->successful() || ! is_file($out)) {
                Log::channel('check_images')->warning('Check PDF could not be rendered', ['error' => trim($result->errorOutput())]);

                return null;
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
