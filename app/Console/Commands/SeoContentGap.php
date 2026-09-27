<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\ContentGapReport;

/**
 * Thin wrapper over the kit's ContentGapReport — ported verbatim from
 * dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. An empty result is STATUS_UNAVAILABLE, which gets the
 * same "print the reason, FAILURE" treatment every other unavailable report
 * gets — one exit-code rule for the whole family.
 */
class SeoContentGap extends KitReportCommand
{
    protected $signature = 'seo:content-gap
        {--days=28 : Days back to aggregate}
        {--min-pos=8 : Minimum average position to consider}
        {--max-pos=20 : Maximum average position to consider}
        {--min-impressions=50 : Drop queries with fewer impressions}
        {--max-clusters=20 : Show top N clusters}
        {--markdown : Save report to storage/app/reports/content-gap.md}';

    protected $description = 'Striking-distance queries clustered into content briefs.';

    protected function reportKey(): string
    {
        return 'content-gap';
    }

    protected function reportClass(): string
    {
        return ContentGapReport::class;
    }

    protected function renderTable(array $data): void
    {
        $clusters = $data['clusters'] ?? [];
        $this->newLine();
        $this->line('--- Content-gap clusters (rank 8-20) ---');
        foreach ($clusters as $i => $cluster) {
            $this->line(sprintf(
                '%d. [%s]  %d impr, %d clicks, avg pos %.2f  (%d queries)',
                $i + 1, $cluster['theme'], $cluster['impr'], $cluster['clicks'], $cluster['avg_pos'], count($cluster['queries']),
            ));
            foreach ($cluster['queries'] as $q) {
                $this->line(sprintf('     • "%s"  → %s  (pos %.2f, %d impr)', $q['query'], $q['page'] ?: '—', $q['pos'], $q['impr']));
            }
        }
    }
}
