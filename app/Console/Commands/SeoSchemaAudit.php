<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\SchemaAuditReport;

/**
 * Thin wrapper over the kit's SchemaAuditReport — ported verbatim from
 * dawnsellshomes' identical command (minus --sitemap: the kit's
 * SiteCatalog::sitemapUrls() always crawls this site's own catalog with no
 * override — see App\Support\Seo\Reports\MarketingSiteCatalog). See
 * SsSystems\Platform\Reports\Console\KitReportCommand. --limit is capped low on this
 * site's SCHEDULED run (routes/console.php).
 */
class SeoSchemaAudit extends KitReportCommand
{
    protected $signature = 'seo:schema-audit
        {--urls= : CSV of explicit URLs to audit (overrides the sitemap)}
        {--limit=80 : Max URLs}
        {--markdown : Save markdown report to storage/app/reports/schema-audit.md}';

    protected $description = 'JSON-LD coverage and validity sweep.';

    protected function reportKey(): string
    {
        return 'schema-audit';
    }

    protected function reportClass(): string
    {
        return SchemaAuditReport::class;
    }

    protected function renderTable(array $data): void
    {
        $this->newLine();
        $this->line('--- Coverage by @type ---');
        $coverage = array_map(fn (array $c) => [$c['type'], $c['urls']], $data['coverage'] ?? []);
        $this->table(['@type', 'URLs'], $coverage);

        $missing = $data['missing_schema'] ?? [];
        if ($missing !== []) {
            $this->newLine();
            $this->line('--- URLs with NO JSON-LD ('.count($missing).') ---');
            foreach (array_slice($missing, 0, 10) as $url) {
                $this->line('  '.$url);
            }
            if (count($missing) > 10) {
                $this->line('  … +'.(count($missing) - 10).' more');
            }
        }
    }

    protected function logAlerts(array $data): void
    {
        $this->logGenericAlert($data);
    }
}
