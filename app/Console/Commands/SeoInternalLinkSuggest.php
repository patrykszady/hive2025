<?php

namespace App\Console\Commands;

use SsSystems\Platform\Reports\Console\KitReportCommand;
use SsSystems\Platform\Reports\InternalLinkSuggestReport;

/**
 * Thin wrapper over the kit's InternalLinkSuggestReport — ported verbatim
 * from dawnsellshomes' identical command. See App\Console\Commands\Seo\
 * KitReportCommand. --limit is capped low on this site's SCHEDULED run
 * (routes/console.php); a manual run keeps the kit's own default.
 */
class SeoInternalLinkSuggest extends KitReportCommand
{
    protected $signature = 'seo:internal-link-suggest
        {--limit=80 : Max URLs to scan as sources}
        {--target-limit=60 : Max target pages to consider}
        {--min-anchor=4 : Minimum anchor-word length (single-word anchors discouraged)}
        {--max-per-page=5 : Cap suggestions per source page}
        {--markdown : Save report to storage/app/reports/internal-link-suggest.md}';

    protected $description = 'Unlinked plain-text mentions of other pages’ anchors.';

    protected function reportKey(): string
    {
        return 'internal-link-suggest';
    }

    protected function reportClass(): string
    {
        return InternalLinkSuggestReport::class;
    }

    protected function renderTable(array $data): void
    {
        $suggestions = $data['suggestions'] ?? [];
        $total = $data['total'] ?? 0;

        $this->newLine();
        $this->line("--- Suggestions: {$total} across ".count($suggestions).' pages ---');
        $shown = 0;
        foreach ($suggestions as $src => $list) {
            if ($shown++ >= 8) {
                $this->line('  … +'.(count($suggestions) - 8).' more pages (see markdown report)');

                break;
            }
            $this->line($src);
            foreach ($list as $s) {
                $this->line(sprintf('  → %s  [anchor: "%s"]', $s['target'], $s['anchor']));
            }
        }
    }
}
