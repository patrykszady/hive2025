<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\ClarityHealthReport;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Thin wrapper over the kit's ClarityHealthReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. ClarityMetricsReader is bound (App\Support\Seo\Reports\
 * ClaritySettingsMetricsReader), so this resolves and runs directly; the
 * admin's "Run" button calls it once App\Support\Seo\Reports\
 * ReportCapabilities lists 'clarity_metrics' as provided — which happens
 * once a Clarity project id and API token are saved from the SEO screen's
 * Connect Services modal. Run without a credential saved, it still
 * generates a report — just one that says "Configured: no" rather than
 * refusing outright.
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
