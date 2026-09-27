<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ClarityHealthReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Thin wrapper over the kit's ClarityHealthReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. Not available on this site: no Microsoft Clarity
 * integration at all — no ClarityMetricsReader binding exists (see
 * App\Support\Seo\Reports\ReportCapabilities). The admin's "Run" button
 * never calls this, and a direct `php artisan seo:clarity-health` fails to
 * resolve ClarityMetricsReader from the container, same as
 * App\Console\Commands\SeoCwvTemplate's psi_snapshots gap.
 */
class SeoClarityHealth extends KitReportCommand
{
    protected $signature = 'seo:clarity-health
        {--markdown : Save markdown report to storage/app/reports/clarity-health.md}';

    protected $description = 'Clarity API/config status, last sync freshness, and latest behavioral metrics snapshot.';

    protected function reportKey(): string
    {
        return 'clarity-health';
    }

    protected function reportClass(): string
    {
        return ClarityHealthReport::class;
    }

    protected function renderTable(array $data): void
    {
        foreach ($data['console_lines'] ?? [] as $line) {
            $this->line($line);
        }
    }

    protected function logAlerts(array $data): void
    {
        $message = $data['spike']['alert_message'] ?? null;
        if ($message === null) {
            return;
        }

        logger()->warning($message, ['spike' => $data['spike']['summary'] ?? null]);
    }

    protected function exitCode(ReportResult $result): int
    {
        return ($result->data['exit'] ?? 'success') === 'failure' ? self::FAILURE : self::SUCCESS;
    }
}
