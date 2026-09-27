<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\AreaPagesAuditReport;

/**
 * Thin wrapper over the kit's AreaPagesAuditReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. Not available on this site (App\Support\Seo\Reports\
 * EmptyAreaCatalog — see App\Support\Seo\Reports\ReportCapabilities): the
 * admin's "Run" button never calls this, and a direct run always returns
 * STATUS_UNAVAILABLE from AreaPagesAuditReport itself (empty
 * AreaCatalog::areas()).
 */
class SeoAreaPagesAudit extends KitReportCommand
{
    protected $signature = 'seo:area-pages-audit
        {--sample=10 : Number of areas to sample (0 = all)}
        {--variants=home,contact,services-kitchen,services-bathroom,services-home : CSV of page variants per area}
        {--thin=350 : Word-count threshold considered "thin"}
        {--sim=0.85 : Jaccard threshold for near-duplicate clustering (0..1)}
        {--shingle=5 : N-gram size for similarity}
        {--markdown : Save markdown report to storage/app/reports/area-pages-audit.md}';

    protected $description = 'Thin pages and near-duplicate clusters across per-area landing pages.';

    protected function reportKey(): string
    {
        return 'area-pages-audit';
    }

    protected function reportClass(): string
    {
        return AreaPagesAuditReport::class;
    }

    protected function renderTable(array $data): void
    {
        $this->newLine();
        $this->info('=== Summary ===');
        foreach ($data['variant_overview'] ?? [] as $v) {
            $this->line(sprintf('  %s — %d pages, words min/avg/max = %d / %d / %d', $v['variant'], $v['pages'], $v['min'], $v['avg'], $v['max']));
        }

        foreach ($data['thin'] ?? [] as $t) {
            $this->line("  [{$t['words']}w] {$t['url']}");
        }

        foreach ($data['clusters'] ?? [] as $variant => $groups) {
            foreach ($groups as $i => $members) {
                $this->line('  '.$variant.' cluster #'.($i + 1).' ('.count($members).' areas): '.implode(', ', $members));
            }
        }

        foreach ($data['fetch_failures'] ?? [] as $u) {
            $this->line('  '.$u);
        }
    }
}
