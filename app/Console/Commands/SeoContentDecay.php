<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ContentDecayReport;

/**
 * Thin wrapper over the kit's ContentDecayReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand.
 */
class SeoContentDecay extends KitReportCommand
{
    protected $signature = 'seo:content-decay
        {--window=28 : Days in each comparison window}
        {--min-impressions=50 : Ignore pages with fewer impressions in the prior window}
        {--click-drop=20 : Flag pages losing >=N% clicks}
        {--pos-drop=2 : Flag pages whose average position worsened by >=N}
        {--limit=40 : Max rows per section}
        {--markdown : Save report to storage/app/reports/content-decay.md}';

    protected $description = 'Pages losing clicks or position week over week.';

    protected function reportKey(): string
    {
        return 'content-decay';
    }

    protected function reportClass(): string
    {
        return ContentDecayReport::class;
    }

    protected function renderTable(array $data): void
    {
        $this->renderRows('Click drops', $data['click_decay'] ?? [], ['Page', 'Prior clicks', 'Recent clicks', '% change'], fn (array $r) => [
            $r['page'], $r['p_clicks'], $r['r_clicks'], sprintf('%+.1f%%', $r['click_pct']),
        ]);

        $this->renderRows('Position regressions', $data['pos_decay'] ?? [], ['Page', 'Prior pos', 'Recent pos', 'Δ pos'], fn (array $r) => [
            $r['page'],
            $r['p_pos'] !== null ? number_format($r['p_pos'], 2) : '—',
            $r['r_pos'] !== null ? number_format($r['r_pos'], 2) : '—',
            sprintf('%+.2f', $r['pos_delta']),
        ]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function renderRows(string $title, array $rows, array $headers, \Closure $shape): void
    {
        $this->newLine();
        $this->line("--- {$title} (".count($rows).') ---');
        if ($rows === []) {
            $this->line('  (none)');

            return;
        }
        $this->table($headers, array_map($shape, $rows));
    }

    protected function logAlerts(array $data): void
    {
        $this->logGenericAlert($data);
    }
}
