<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\HealthReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Thin wrapper over the kit's HealthReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand.
 *
 * --json short-circuits everything else: no markdown save, no console
 * table, always SUCCESS — App\Http\Controllers\Api\Admin\V1\
 * SeoSnapshotController::healthSnapshot() calls
 * `Artisan::call('seo:health --json')` and only ever reads the JSON.
 *
 * Otherwise: HealthReport::generate() already appends today's score to the
 * health ledger internally regardless of these options — quiet_on_pass has
 * NO effect on the algorithm, only on the exit code (see exitCode() below).
 */
class SeoHealth extends KitReportCommand
{
    protected $signature = 'seo:health
        {--json : Output JSON only}
        {--markdown : Save markdown report to storage/app/reports/health.md}
        {--quiet-on-pass : Exit silently when score >= 90}';

    protected $description = 'Unified 0–100 SEO pillar dashboard with freshness, rankings, GBP and on-page signals.';

    protected function reportKey(): string
    {
        return 'health';
    }

    protected function reportClass(): string
    {
        return HealthReport::class;
    }

    public function handle(): int
    {
        /** @var HealthReport $report */
        $report = $this->laravel->make($this->reportClass());
        $result = $report->generate($this->reportOptions());

        if ($this->option('json')) {
            $this->line(json_encode([
                'score' => $result->data['score'] ?? null,
                'pillars' => $result->data['pillars'] ?? [],
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->respond($result);
    }

    protected function renderTable(array $data): void
    {
        $rows = array_map(fn (array $p) => [
            $p['name'],
            $p['score'] === null ? 'not measured' : $p['score'],
            $p['bar'],
        ], $data['pillars'] ?? []);

        $this->newLine();
        $score = $data['score'] ?? null;
        $this->line('SEO Health Score: '.($score === null ? 'not measured yet' : "{$score}/100").' ('.($data['grade'] ?? '').')');
        $this->newLine();
        $this->table(['Pillar', 'Score', 'Bar'], $rows);

        foreach ($data['pillars'] ?? [] as $pillar) {
            $this->line($pillar['name'].' — '.($pillar['score'] === null ? 'not measured yet' : "{$pillar['score']}/100"));
            foreach ($pillar['metrics'] ?? [] as $key => $value) {
                $this->line("  · {$key}: {$value}");
            }
            if (! empty($pillar['fix'])) {
                $this->line("  → {$pillar['fix']}");
            }
            $this->newLine();
        }
    }

    protected function exitCode(ReportResult $result): int
    {
        $score = $result->data['score'] ?? null;
        $quietOnPass = (bool) ($result->data['quiet_on_pass'] ?? false);

        if ($quietOnPass && $score !== null && $score >= 90) {
            return self::SUCCESS;
        }

        return ($score !== null && $score >= 70) ? self::SUCCESS : self::FAILURE;
    }
}
