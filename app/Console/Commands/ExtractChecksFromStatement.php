<?php

namespace App\Console\Commands;

use App\Models\CheckImage;
use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;

class ExtractChecksFromStatement extends Command
{
    protected $signature = 'cu:extract-checks
                            {file          : Path to the bank statement check-images PDF}
                            {--analyzer=   : Analyzer ID override (defaults to services.azure_cu.analyzer_id_check_statement)}
                            {--out=        : Output directory for cropped check images (default: storage/files/checks)}
                            {--dpi=200     : Render resolution used when cropping check images}
                            {--dump        : Also write the raw CU response JSON next to the crops}';

    protected $description = 'Run the check-statement Content Understanding analyzer on a PDF: print a table of checks (number/date/amount) and crop each check image.';

    public function handle(ContentUnderstandingService $cu): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $analyzerId = $this->option('analyzer') ?: config('services.azure_cu.analyzer_id_check_statement');
        $outDir     = $this->option('out') ?: \Illuminate\Support\Facades\Storage::disk('files')->path('checks');
        $dpi        = max(72, (int) $this->option('dpi'));

        $this->info("Analyzing with '{$analyzerId}'...");

        $raw      = $cu->analyzeBinary(file_get_contents($file), $analyzerId);
        $contents = $raw['result']['contents'] ?? [];

        File::ensureDirectoryExists($outDir);

        // Keep a copy of the source statement PDF alongside the crops.
        File::ensureDirectoryExists($outDir . '/files');
        File::copy($file, $outDir . '/files/' . basename($file));

        if ($this->option('dump')) {
            $dumpPath = $outDir . '/files/' . pathinfo($file, PATHINFO_FILENAME) . '-cu-response.json';
            file_put_contents($dumpPath, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Raw response written to {$dumpPath}");
        }

        $account = 'unknown';
        foreach ($contents as $content) {
            $value = trim((string) ($content['fields']['AccountNumber']['valueString'] ?? ''));
            if ($value !== '') {
                $account = preg_replace('/\D+/', '', $value) ?: $value;
                break;
            }
        }

        $checks = $this->collectChecks($contents);

        if ($checks === []) {
            $this->error('No checks were extracted.');

            return self::FAILURE;
        }

        $this->table(
            ['#', 'Check No', 'Date', 'Amount', 'Page'],
            collect($checks)->map(fn ($c, $i) => [
                $i + 1,
                $c['number'],
                $c['date'],
                $c['amount'] !== null ? number_format($c['amount'], 2) : '',
                $c['page'] ?? '?',
            ])->all()
        );

        $total = collect($checks)->sum('amount');
        $this->info(count($checks) . ' checks, total $' . number_format($total, 2));

        // ── Crop each check image ─────────────────────────────────────────
        // Ingest check_images rows only for the canonical storage location.
        $ingest  = ! $this->option('out');
        $cropped = $this->cropCheckImages($file, $checks, $outDir, $dpi, $account, $ingest);
        $this->info("{$cropped} check image(s) saved to {$outDir}");

        if ($ingest) {
            $this->info('check_images rows upserted — run `php artisan cu:process-check-images` to analyze and link them.');
        }

        return self::SUCCESS;
    }

    /**
     * Upsert the check_images row for a cropped check. Keyed on the
     * deterministic filename so statement re-imports are idempotent; only
     * stage-1 (statement caption) fields are written, so analyzer output and
     * match links survive re-ingestion.
     */
    protected function ingestCheckImage(string $filename, array $check, string $account, string $statementFilename): void
    {
        $existing = CheckImage::where('image_filename', $filename)->first();

        $attributes = [
            'statement_filename' => $statementFilename,
            'check_number'       => $check['number'] !== null ? (int) $check['number'] : null,
            'amount'             => $check['amount'],
            'check_date'         => $check['date'],
        ];

        // The statement-header account is a fallback — never clobber a MICR
        // account number captured by the per-check analyzer.
        if ($account !== 'unknown' && (! $existing || empty($existing->account_number))) {
            $attributes['account_number'] = $account;
        }

        if ($existing && ((int) $existing->check_number !== (int) $attributes['check_number']
            || (float) $existing->amount !== (float) ($check['amount'] ?? 0))) {
            Log::channel('check_images')->warning('Re-ingested check image differs from existing row', [
                'image'    => $filename,
                'existing' => ['check_number' => $existing->check_number, 'amount' => $existing->amount],
                'incoming' => ['check_number' => $attributes['check_number'], 'amount' => $check['amount']],
            ]);
        }

        CheckImage::updateOrCreate(['image_filename' => $filename], $attributes);
    }

    /**
     * Flatten the CU response into one entry per check with its caption
     * bounding box (inches) parsed from the field `source` polygons.
     *
     * @return array<int, array{number: ?string, date: ?string, amount: ?float, page: ?int, box: ?array{x0: float, y0: float, x1: float, y1: float}}>
     */
    private function collectChecks(array $contents): array
    {
        $captionLines = $this->collectCaptionLines($contents);
        $checks       = [];

        foreach ($contents as $content) {
            $rows = $content['fields']['Checks']['valueArray'] ?? [];

            foreach ($rows as $row) {
                $fields = $row['valueObject'] ?? [];

                $sources = [];
                foreach (['CheckNumber', 'CheckDate', 'CheckAmount'] as $name) {
                    foreach ($this->parseSourcePolygons($fields[$name]['source'] ?? ($fields[$name]['spans'][0]['source'] ?? null)) as $poly) {
                        $sources[] = $poly;
                    }
                }
                // The array element itself may carry a source covering the whole check region.
                foreach ($this->parseSourcePolygons($row['source'] ?? null) as $poly) {
                    $sources[] = $poly;
                }

                $number = $fields['CheckNumber']['valueString'] ?? null;
                $box    = $this->unionBox($sources);
                $page   = $sources[0]['page'] ?? null;

                // Prefer the full OCR caption line ("Ck Date: ... Ck No: N Amt: ...")
                // over the grounded value boxes — it spans the whole caption
                // including labels, which tracks the true check width.
                if ($number !== null) {
                    $caption = $this->matchCaptionLine($captionLines, $number, $box);
                    if ($caption) {
                        $box  = $caption['box'];
                        $page = $caption['page'];
                    }
                }

                $checks[] = [
                    'number' => $number,
                    'date'   => $fields['CheckDate']['valueDate'] ?? ($fields['CheckDate']['valueString'] ?? null),
                    'amount' => isset($fields['CheckAmount']['valueNumber']) ? (float) $fields['CheckAmount']['valueNumber'] : null,
                    'page'   => $page,
                    'box'    => $box,
                ];
            }
        }

        return $checks;
    }

    /**
     * All OCR lines that look like check captions, with page + box.
     *
     * @return array<int, array{content: string, page: int, box: array}>
     */
    private function collectCaptionLines(array $contents): array
    {
        $lines = [];

        foreach ($contents as $content) {
            foreach ($content['pages'] ?? [] as $page) {
                foreach ($page['lines'] ?? [] as $line) {
                    $text = (string) ($line['content'] ?? '');
                    if (! str_contains($text, 'Ck No')) {
                        continue;
                    }

                    $boxes = $this->parseSourcePolygons($line['source'] ?? null);
                    if ($boxes === []) {
                        continue;
                    }

                    $lines[] = [
                        'content' => $text,
                        'page'    => $boxes[0]['page'],
                        'box'     => $this->unionBox($boxes),
                    ];
                }
            }
        }

        return $lines;
    }

    /**
     * Find the caption line for a check number, preferring the one closest to
     * the grounded value box when the same number appears more than once.
     */
    private function matchCaptionLine(array $captionLines, string $number, ?array $valueBox): ?array
    {
        $candidates = array_values(array_filter(
            $captionLines,
            fn ($l) => preg_match('/Ck No:?\s*' . preg_quote($number, '/') . '\b/', $l['content']) === 1
        ));

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) > 1 && $valueBox) {
            usort($candidates, fn ($a, $b) =>
                abs($a['box']['y0'] - $valueBox['y0']) <=> abs($b['box']['y0'] - $valueBox['y0']));
        }

        return $candidates[0];
    }

    /**
     * Parse a CU source string like
     *   "D(1,0.5297,2.1965,3.4661,2.1965,3.4661,2.3623,0.5297,2.3623)"
     * (possibly several regions separated by ";") into page + polygon boxes.
     * Coordinates are in inches for PDF inputs.
     *
     * @return array<int, array{page: int, x0: float, y0: float, x1: float, y1: float}>
     */
    private function parseSourcePolygons(?string $source): array
    {
        if (! $source) {
            return [];
        }

        $boxes = [];

        foreach (explode(';', $source) as $chunk) {
            if (! preg_match('/D\((\d+),([-0-9.,\s]+)\)/', trim($chunk), $m)) {
                continue;
            }

            $coords = array_map('floatval', array_filter(array_map('trim', explode(',', $m[2])), fn ($v) => $v !== ''));
            if (count($coords) < 4) {
                continue;
            }

            $xs = $ys = [];
            foreach ($coords as $i => $v) {
                $i % 2 === 0 ? $xs[] = $v : $ys[] = $v;
            }

            $boxes[] = [
                'page' => (int) $m[1],
                'x0'   => min($xs),
                'y0'   => min($ys),
                'x1'   => max($xs),
                'y1'   => max($ys),
            ];
        }

        return $boxes;
    }

    /**
     * @param  array<int, array{page: int, x0: float, y0: float, x1: float, y1: float}>  $boxes
     */
    private function unionBox(array $boxes): ?array
    {
        if ($boxes === []) {
            return null;
        }

        // Union only boxes on the first box's page.
        $page  = $boxes[0]['page'];
        $boxes = array_values(array_filter($boxes, fn ($b) => $b['page'] === $page));

        return [
            'x0' => min(array_column($boxes, 'x0')),
            'y0' => min(array_column($boxes, 'y0')),
            'x1' => max(array_column($boxes, 'x1')),
            'y1' => max(array_column($boxes, 'y1')),
        ];
    }

    /**
     * Crop each check image out of the PDF. The grounded caption box sits
     * directly BELOW its check image, so the check region is the band above
     * the caption, clamped so it never overlaps the caption of the row above.
     */
    private function cropCheckImages(string $pdfPath, array $checks, string $outDir, int $dpi, string $account = 'unknown', bool $ingest = false): int
    {
        $byPage = collect($checks)->filter(fn ($c) => $c['box'] && $c['page'])->groupBy('page');
        $count  = 0;

        foreach ($byPage as $page => $pageChecks) {
            $pagePng = $outDir . "/_page{$page}.png";
            $this->renderPage($pdfPath, (int) $page, $pagePng, $dpi);

            [$pageW, $pageH] = $this->pngSize($pagePng);

            // Measure the typical check band height (image + caption) from rows
            // that have a row above them, so top-row checks get the same height
            // instead of swallowing the page header.
            $bands = [];
            foreach ($pageChecks as $check) {
                $ceiling = $this->columnCeiling($pageChecks->all(), $check);
                if ($ceiling > 0) {
                    $bands[] = ($check['box']['y1'] + 0.08) - $ceiling;
                }
            }
            sort($bands);
            $bandHeight = $bands !== [] ? $bands[intdiv(count($bands), 2)] : 2.1;

            foreach ($pageChecks as $check) {
                $cap = $check['box'];

                // Horizontal: the check image is wider than its caption. Pad
                // generously, but never cross the midpoint of the gap toward a
                // check in the adjacent column on the same row.
                [$leftLimit, $rightLimit] = $this->rowNeighborLimits($pageChecks->all(), $check, $pageW / $dpi);
                $x0 = max(min($leftLimit, $cap['x0']), $cap['x0'] - 0.15);
                $x1 = min(max($rightLimit, $cap['x1']), $cap['x1'] + 0.15);

                // Vertical: include the caption line below the check image; the
                // top is bounded by the caption of the check directly above in
                // the same column, and top-row checks use the measured band height.
                $bottom  = $cap['y1'] + 0.08;
                $ceiling = $this->columnCeiling($pageChecks->all(), $check);
                $top     = $ceiling > 0 ? $ceiling : max(0.0, $bottom - $bandHeight);

                $px = (int) floor($x0 * $dpi);
                $py = (int) floor($top * $dpi);
                $pw = (int) ceil(($x1 - $x0) * $dpi);
                $ph = (int) ceil(($bottom - $top) * $dpi);

                $px = max(0, min($px, $pageW - 1));
                $py = max(0, min($py, $pageH - 1));
                $pw = min($pw, $pageW - $px);
                $ph = min($ph, $pageH - $py);

                if ($pw < 10 || $ph < 10) {
                    $this->warn("Skipping check {$check['number']}: degenerate crop region");
                    continue;
                }

                $target = $outDir . '/' . implode('_', [
                    $account,
                    $check['number'] ?: 'unknown' . $count,
                    $check['amount'] !== null ? number_format($check['amount'], 2, '.', '') : 'unknown',
                    $check['date'] ?: 'unknown',
                ]) . '.png';
                Process::timeout(60)->run([
                    'convert', $pagePng, '-crop', "{$pw}x{$ph}+{$px}+{$py}", '+repage', $target,
                ])->throw();

                if ($ingest) {
                    $this->ingestCheckImage(basename($target), $check, $account, basename($pdfPath));
                }

                $count++;
            }

            @unlink($pagePng);
        }

        return $count;
    }

    /**
     * The lowest caption bottom edge of any check ABOVE this one in the same
     * column (overlapping x range) — the top boundary of this check's image.
     * Falls back to a fixed offset when the check is in the top row.
     */
    private function columnCeiling(array $pageChecks, array $check): float
    {
        $cap     = $check['box'];
        $ceiling = 0.0;

        foreach ($pageChecks as $other) {
            if ($other === $check || ! $other['box']) {
                continue;
            }

            $ob = $other['box'];
            $overlapsX = min($cap['x1'], $ob['x1']) - max($cap['x0'], $ob['x0']) > 0.3;

            if ($overlapsX && $ob['y1'] < $cap['y0'] - 0.05) {
                $ceiling = max($ceiling, $ob['y1'] + 0.09);
            }
        }

        return $ceiling;
    }

    /**
     * Horizontal crop limits for a check: the midpoint of the gap between its
     * caption and the caption of any check on the same row (adjacent column),
     * falling back to the page edges.
     *
     * @return array{0: float, 1: float}
     */
    private function rowNeighborLimits(array $pageChecks, array $check, float $pageWidth): array
    {
        $cap   = $check['box'];
        $left  = 0.0;
        $right = $pageWidth;

        foreach ($pageChecks as $other) {
            if ($other === $check || ! $other['box']) {
                continue;
            }

            $ob = $other['box'];
            $overlapsY = min($cap['y1'], $ob['y1']) - max($cap['y0'], $ob['y0']) > -0.5;

            if (! $overlapsY) {
                continue;
            }

            if ($ob['x1'] <= $cap['x0']) {
                $left = max($left, ($ob['x1'] + $cap['x0']) / 2);
            } elseif ($ob['x0'] >= $cap['x1']) {
                $right = min($right, ($cap['x1'] + $ob['x0']) / 2);
            }
        }

        return [$left, $right];
    }

    private function renderPage(string $pdfPath, int $page, string $outPng, int $dpi): void
    {
        Process::timeout(120)->run([
            'gs', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=png16m',
            "-r{$dpi}", "-dFirstPage={$page}", "-dLastPage={$page}",
            "-sOutputFile={$outPng}", $pdfPath,
        ])->throw();

        if (! is_file($outPng)) {
            throw new ProcessFailedException(Process::result('', 'ghostscript produced no output', 1));
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pngSize(string $path): array
    {
        $info = getimagesize($path);

        return [$info[0] ?? 0, $info[1] ?? 0];
    }
}
