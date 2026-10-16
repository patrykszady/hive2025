<?php

namespace App\Services\Audits;

use App\Support\Pdf\MarkerFpdi;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Marks the "Checks Paid" table of a bank statement for an auditor: each
 * check the bank cleared is highlighted green when Hive has it (same number,
 * same amount), yellow when it does not, and struck through when it was paid
 * outside the audit period (2026-10-16). The statement's text and positions
 * come from Ghostscript (txtwrite), the marks are drawn over the original
 * pages with FPDI; anything that cannot be read leaves the statement exactly
 * as the bank sent it.
 */
class StatementCheckHighlighter
{
    /** Highlighter tints (RGB), laid on with Multiply so the print stays crisp. */
    public const GREEN = [187, 247, 208];

    public const YELLOW = [254, 240, 138];

    /** The strike through a check outside the audit period. */
    public const STRIKE = [82, 82, 91];

    public const CONFIRMED = 'confirmed';

    public const MISSING = 'missing';

    public const OUTSIDE = 'outside';

    /**
     * @param  callable(int $number, float $amount, string $date): string  $statusOf  CONFIRMED, MISSING or OUTSIDE ($date as printed, "08/27")
     * @return array{pdf: string, checks: list<array{page: int, number: int, date: string, amount: float, status: string, confirmed: bool}>}
     */
    public function highlight(string $pdf, callable $statusOf): array
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $checks = array_map(function (array $check) use ($statusOf): array {
                $check['status'] = (string) $statusOf($check['number'], $check['amount'], $check['date']);
                $check['confirmed'] = $check['status'] === self::CONFIRMED;

                return $check;
            }, $this->checksPaid($path));

            if ($checks === []) {
                return ['pdf' => $pdf, 'checks' => []];
            }

            return ['pdf' => $this->draw($path, $checks), 'checks' => array_map(fn (array $check) => array_diff_key($check, ['box' => true, 'legend' => true]), $checks)];
        } catch (Throwable $exception) {
            Log::channel('plaid_statements')->warning('Statement check highlighting skipped', ['error' => $exception->getMessage()]);

            return ['pdf' => $pdf, 'checks' => []];
        } finally {
            @unlink($path);
        }
    }

    /**
     * The day a check printed as "08/27" was paid, on a statement posted on
     * $postedOn: that year, or the one before when the date would otherwise
     * fall after the statement (a December check on a January statement).
     */
    public static function paidOn(string $printed, \Carbon\CarbonInterface $postedOn): ?\Carbon\Carbon
    {
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $printed, $parts) || ! checkdate((int) $parts[1], (int) $parts[2], 2000)) {
            return null;
        }

        if (! empty($parts[3])) {
            $year = (int) $parts[3];

            return \Carbon\Carbon::create($year < 100 ? 2000 + $year : $year, (int) $parts[1], (int) $parts[2]);
        }

        $date = \Carbon\Carbon::create($postedOn->year, (int) $parts[1], (int) $parts[2]);

        return $date->gt($postedOn->copy()->addDays(7)) ? $date->subYear() : $date;
    }

    /**
     * Every row of every "Checks Paid" table: check number, date and amount
     * side by side (a "*" after the number marks a gap in the sequence), with
     * the box around the three, in points from the page's top left.
     *
     * @return list<array{page: int, number: int, date: string, amount: float, box: array{0: float, 1: float, 2: float, 3: float}, legend: array{0: float, 1: float, 2: float}}>
     */
    public function checksPaid(string $path): array
    {
        $checks = [];

        foreach ($this->pages($path) as $pageNumber => $words) {
            $lines = collect($words)->groupBy(fn (array $word) => (int) round($word['y']))->sortKeys();
            $heading = null;
            $legend = null;
            $foundRows = false;

            // A "Checks Paid" heading counts only with its "Check Date Amount"
            // column headings right below it: a summary line elsewhere on the
            // page ("Checks Paid  40,350.00") is not the table.
            foreach ($lines as $y => $line) {
                if ($this->lineHas($line, ['Checks', 'Paid'])) {
                    $heading = $y;

                    continue;
                }

                if ($this->lineHas($line, ['Check', 'Date', 'Amount'])) {
                    if ($heading !== null && $y - $heading <= 30) {
                        $headingWords = $lines[$heading]->sortBy('x0')->values();
                        $legend = [$headingWords->last()['x1'] + 14, (float) $heading, $headingWords->first()['size']];
                        $foundRows = false;
                    }

                    continue;
                }

                if ($legend === null) {
                    continue;
                }

                $rowChecks = $this->rowChecks($line->sortBy('x0')->values()->all());

                if ($rowChecks === []) {
                    if ($foundRows) {
                        $legend = null;
                        $heading = null;
                    }

                    continue;
                }

                $foundRows = true;
                foreach ($rowChecks as $check) {
                    $checks[] = ['page' => $pageNumber, 'legend' => $legend] + $check;
                }
            }
        }

        return $checks;
    }

    /**
     * Words per page from Ghostscript's txtwrite (TextFormat=1): spans of
     * characters, each with its box; a span is cut into words at spaces.
     *
     * @return array<int, list<array{text: string, x0: float, x1: float, y: float, size: float}>>
     */
    protected function pages(string $path): array
    {
        $out = tempnam(sys_get_temp_dir(), 'stmt-text');
        $process = new Process(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=txtwrite', '-dTextFormat=1', '-sOutputFile='.$out, $path]);
        $process->setTimeout(60);
        $process->run();
        $xml = (string) @file_get_contents($out);
        @unlink($out);

        $pages = [];

        foreach (explode('</page>', $xml) as $index => $page) {
            $words = [];

            preg_match_all('/<span bbox="[^"]*" font="[^"]*" size="([\d.]+)">(.*?)<\/span>/s', $page, $spans, PREG_SET_ORDER);
            foreach ($spans as [, $size, $body]) {
                preg_match_all('/<char bbox="([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+)" c="([^"]*)"\/>/', $body, $chars, PREG_SET_ORDER);

                $word = null;
                foreach ($chars as [, $x0, $y0, $x1, , $c]) {
                    $c = html_entity_decode($c, ENT_QUOTES | ENT_XML1);

                    if (trim($c) === '') {
                        if ($word) {
                            $words[] = $word;
                            $word = null;
                        }

                        continue;
                    }

                    $word = $word
                        ? ['text' => $word['text'].$c, 'x1' => (float) $x1] + $word
                        : ['text' => $c, 'x0' => (float) $x0, 'x1' => (float) $x1, 'y' => (float) $y0, 'size' => (float) $size];
                }

                if ($word) {
                    $words[] = $word;
                }
            }

            if ($words !== []) {
                $pages[$index + 1] = $words;
            }
        }

        return $pages;
    }

    /** @param  iterable<array{text: string}>  $line */
    protected function lineHas(iterable $line, array $texts): bool
    {
        $present = collect($line)->pluck('text')->all();

        return collect($texts)->every(fn (string $text) => in_array($text, $present, true));
    }

    /**
     * "2459*  08/12  7,500.00": number, date, amount, left to right.
     *
     * @param  list<array{text: string, x0: float, x1: float, y: float, size: float}>  $words
     * @return list<array{number: int, date: string, amount: float, box: array{0: float, 1: float, 2: float, 3: float}}>
     */
    protected function rowChecks(array $words): array
    {
        $checks = [];

        for ($i = 0; $i + 2 < count($words); $i++) {
            [$number, $date, $amount] = [$words[$i], $words[$i + 1], $words[$i + 2]];

            if (preg_match('/^(\d{3,8})\*?$/', $number['text'], $n)
                && preg_match('/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $date['text'])
                && preg_match('/^-?[\d,]+\.\d{2}$/', $amount['text'])) {
                $size = $number['size'];
                $checks[] = [
                    'number' => (int) $n[1],
                    'date' => $date['text'],
                    'amount' => abs((float) str_replace(',', '', $amount['text'])),
                    // Glyph height plus a hair, so the rows' tints don't touch.
                    'box' => [$number['x0'] - 2.5, $number['y'] - $size * 0.8, $amount['x1'] + 2.5, $number['y'] + $size * 0.18],
                ];
                $i += 2;
            }
        }

        return $checks;
    }

    /**
     * The statement redrawn page by page, each check's row tinted, and a
     * one-line key beside each "Checks Paid" heading.
     *
     * @param  list<array{page: int, confirmed: bool, box: array{0: float, 1: float, 2: float, 3: float}, legend: array{0: float, 1: float, 2: float}}>  $checks
     */
    protected function draw(string $path, array $checks): string
    {
        $byPage = collect($checks)->groupBy('page');
        $pdf = new MarkerFpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($path);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            if (! $byPage->has($page)) {
                continue;
            }

            $pdf->setMarker();
            foreach ($byPage[$page]->where('status', '!=', self::OUTSIDE) as $check) {
                [$x0, $y0, $x1, $y1] = $check['box'];
                $pdf->SetFillColor(...($check['confirmed'] ? self::GREEN : self::YELLOW));
                $pdf->Rect($x0, $y0, $x1 - $x0, $y1 - $y0, 'F');
            }
            $pdf->setMarker(false);

            // Paid outside the audit period: crossed off, not judged.
            $pdf->SetDrawColor(...self::STRIKE);
            $pdf->SetLineWidth(0.8);
            foreach ($byPage[$page]->where('status', self::OUTSIDE) as $check) {
                [$x0, $y0, $x1, $y1] = $check['box'];
                $middle = $y0 + ($y1 - $y0) * 0.55;
                $pdf->Line($x0, $middle, $x1, $middle);
            }

            [$x, $y, $fontSize] = $byPage[$page]->first()['legend'];
            $this->drawLegend($pdf, $x, $y, max(6.0, $fontSize - 2.5));
        }

        return $pdf->Output('S');
    }

    /** "■ in Hive  ■ not found in Hive  — outside the audit", small and grey, on the heading's line. */
    protected function drawLegend(MarkerFpdi $pdf, float $x, float $baseline, float $fontSize): void
    {
        $pdf->SetFont('Helvetica', '', $fontSize);
        $pdf->SetTextColor(82, 82, 91);
        $swatch = $fontSize * 0.9;

        foreach ([[self::GREEN, 'in Hive'], [self::YELLOW, 'not found in Hive'], [null, 'outside the audit']] as [$colour, $label]) {
            if ($colour) {
                $pdf->setMarker();
                $pdf->SetFillColor(...$colour);
                $pdf->Rect($x, $baseline - $swatch, $swatch, $swatch, 'F');
                $pdf->setMarker(false);
            } else {
                $pdf->SetDrawColor(...self::STRIKE);
                $pdf->SetLineWidth(0.8);
                $pdf->Line($x, $baseline - $swatch * 0.45, $x + $swatch, $baseline - $swatch * 0.45);
            }
            $pdf->Text($x + $swatch + 2, $baseline, $label);
            $x += $swatch + 2 + $pdf->GetStringWidth($label) + 8;
        }
    }
}
