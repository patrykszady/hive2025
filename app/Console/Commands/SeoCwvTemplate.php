<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\CwvTemplateReport;

/**
 * Thin wrapper over the kit's CwvTemplateReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. Not available on this site (no psi_snapshots table —
 * see App\Support\Seo\Reports\ReportCapabilities): the admin's "Run"
 * button never calls this, and a direct `php artisan seo:cwv-template`
 * fails to resolve PsiSnapshotReader from the container, since nothing
 * binds it here.
 */
class SeoCwvTemplate extends KitReportCommand
{
    protected $signature = 'seo:cwv-template
        {--window=7 : Days in each comparison window}
        {--lcp-regress=150 : Flag templates whose p75 LCP grew by >=N ms (field/CrUX-backed)}
        {--lab-lcp-regress=500 : LCP threshold (ms) for lab-only buckets; lab mobile LCP is synthetic and noisy}
        {--inp-regress=40 : Flag templates whose p75 INP grew by >=N ms}
        {--cls-regress=0.02 : Flag templates whose p75 CLS grew by >=N}
        {--min-samples=10 : Require at least N samples in BOTH windows before flagging a regression}
        {--markdown : Save report to storage/app/reports/cwv-template.md}';

    protected $description = 'p75 LCP/INP/CLS per page template with regression alerts.';

    protected function reportKey(): string
    {
        return 'cwv-template';
    }

    protected function reportClass(): string
    {
        return CwvTemplateReport::class;
    }

    protected function renderTable(array $data): void
    {
        $rows = array_map(fn (array $r) => [
            $r['template'], $r['strategy'], $r['samples'], $r['lcp'] ?? '—', $r['inp'] ?? '—', $r['cls'] ?? '—', $r['perf'] ?? '—',
        ], $data['rows'] ?? []);

        $this->table(['Template', 'Strategy', 'Samples', 'LCP p75', 'INP p75', 'CLS p75', 'Perf'], $rows);

        $alerts = $data['alerts'] ?? [];
        $this->newLine();
        $this->line('--- Alerts ('.count($alerts).') ---');
        foreach ($alerts as $alert) {
            $this->line('  '.$alert);
        }
    }

    protected function logAlerts(array $data): void
    {
        $this->logGenericAlert($data);
    }
}
