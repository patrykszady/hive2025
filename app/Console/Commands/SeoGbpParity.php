<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\GbpParityReport;

/**
 * Thin wrapper over the kit's GbpParityReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand and App\Support\Seo\Reports\ConfigSiteIdentity for what
 * this site's NAP/service-catalog check actually reads (both null here —
 * no public phone/address, no GBP service catalog).
 */
class SeoGbpParity extends KitReportCommand
{
    protected $signature = 'seo:gbp-parity
        {--landing-pages=/,/contact,/about : CSV of paths to check for NAP}
        {--markdown : Save report to storage/app/reports/gbp-parity.md}';

    protected $description = 'NAP consistency and Google Business Profile ↔ site service parity.';

    protected function reportKey(): string
    {
        return 'gbp-parity';
    }

    protected function reportClass(): string
    {
        return GbpParityReport::class;
    }

    protected function renderTable(array $data): void
    {
        $this->info('Expected phone (normalized): '.($data['expected_phone'] ?: '(none configured)'));
        $this->info('Expected address fragment: '.($data['expected_address'] !== '' ? $data['expected_address'] : '(none configured)'));

        $this->newLine();
        $this->line('--- NAP per landing page ---');
        $rows = [];
        foreach ($data['nap'] ?? [] as $url => $r) {
            $glyph = fn (?bool $v) => $v === null ? 'skip' : ($v ? 'match' : 'MISMATCH');
            $rows[] = [$url, $r['phone'], $glyph($r['phone_ok']), $glyph($r['address_ok'])];
        }
        $this->table(['URL', 'Phone(s)', 'Phone match', 'Addr match'], $rows);

        $this->newLine();
        $this->line('--- Service parity ---');
        $this->line('  Pillars covered ('.count($data['pillars_covered']).'): '.(empty($data['pillars_covered']) ? '—' : implode(', ', $data['pillars_covered'])));
        $this->line('  Pillars MISSING ('.count($data['pillars_missing']).'): '.(empty($data['pillars_missing']) ? '—' : implode(', ', $data['pillars_missing'])));
        $this->line('  Sub-services rolled up ('.count($data['subs_rolled']).'): '.(empty($data['subs_rolled']) ? '—' : implode(', ', array_keys($data['subs_rolled']))));
        $this->line('  Sub-services ORPHANED ('.count($data['subs_orphan']).'): '.(empty($data['subs_orphan']) ? '—' : implode(', ', array_keys($data['subs_orphan']))));
        $this->line('  On site, missing in GBP ('.count($data['site_only']).'): '.(empty($data['site_only']) ? '—' : implode(', ', $data['site_only'])));
    }

    protected function logAlerts(array $data): void
    {
        $issues = $data['issues'] ?? [];
        if ($issues === []) {
            return;
        }

        logger()->warning('seo:gbp-parity issues', [
            'count' => count($issues),
            'first' => array_slice($issues, 0, 5),
        ]);
    }
}
