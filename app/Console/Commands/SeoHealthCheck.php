<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\HealthCheckReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Thin wrapper over the kit's HealthCheckReport — ported verbatim from
 * dawnsellshomes' identical command (minus --sitemap, same reasoning as
 * SeoSchemaAudit). See SsSystems\Platform\Reports\Console\KitReportCommand.
 *
 * Exit code: a complete result whose worst-scoring URL falls below
 * --min-score is STATUS_DEGRADED (data['missing'] lists the URLs below
 * threshold) rather than STATUS_ERROR, which would have suppressed the
 * markdown/table this command still needs to produce. This override turns
 * that STATUS_DEGRADED into a non-zero exit code.
 */
class SeoHealthCheck extends KitReportCommand
{
    protected $signature = 'seo:health-check
        {--urls= : CSV of explicit URLs (overrides the sitemap)}
        {--limit=60 : Max URLs}
        {--min-score=80 : Fail (non-zero exit) if any URL scores below this}
        {--markdown : Save markdown report to storage/app/reports/health-check.md}';

    protected $description = 'Composite 0–100 score per URL across title, meta, H1, alt, links, schema, canonical, word count.';

    protected function reportKey(): string
    {
        return 'health-check';
    }

    protected function reportClass(): string
    {
        return HealthCheckReport::class;
    }

    protected function renderTable(array $data): void
    {
        foreach ($data['rows'] ?? [] as $row) {
            $this->line(sprintf('  [%3d] %s', $row['score'], $row['url']));
        }

        $this->newLine();
        $this->info('=== Health-check summary ===');
        $this->line('Average score: '.($data['average_score'] ?? 0).' / 100');

        $this->newLine();
        $this->line('Worst 10:');
        $rows = array_map(function (array $r) {
            $failed = collect($r['breakdown'])
                ->filter(fn (array $v) => ! $v['ok'])
                ->map(fn (array $v, string $k) => "{$k}: {$v['note']}")
                ->values()
                ->implode('; ');

            return [$r['score'], $r['url'], $failed ?: '—'];
        }, array_slice($data['rows'] ?? [], 0, 10));

        $this->table(['Score', 'URL', 'Failing checks'], $rows);
    }

    protected function exitCode(ReportResult $result): int
    {
        return $result->status === ReportResult::STATUS_DEGRADED ? self::FAILURE : self::SUCCESS;
    }
}
