<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Inspection\SweepOptions;
use SsSystems\Platform\Seo\Inspection\UrlInspectionSweep;

/**
 * Thin wrapper around the kit's UrlInspectionSweep — ported from
 * dawnsellshomes' identical command. The sweep algorithm itself (pool
 * resolution, prioritization, persistence, the markdown report) is
 * entirely SsSystems\Platform\Seo\Inspection\UrlInspectionSweep::run();
 * this class only turns its SweepOptions/SweepResult into console output,
 * a saved markdown file and an exit code. See App\Support\Seo\Inspection\
 * {SearchConsoleUrlInspector,MarketingSitemapSource,EloquentCoverageStore,
 * NoTrackedPaths} for the site-specific reads/writes the sweep drives, and
 * App\Providers\AppServiceProvider for how they (and UrlInspectionQuota)
 * are bound.
 *
 * Exit code: SweepResult carries no status field of its own — the one real
 * FAILURE case is an empty resolved pool ("Nothing to inspect.") or no
 * Search Console grant at all; every other early return (today's allowance
 * already spent, a mid-run 429, a normal completion) is SUCCESS.
 */
class SeoGscInspectBulk extends Command
{
    protected $signature = 'seo:gsc-inspect-bulk
        {--limit=0 : Maximum URLs to inspect this run (0 = all sitemap URLs)}
        {--sitemap= : Path to sitemap XML (default: this site\'s own URL inventory)}
        {--strategy=stale : URL selection: stale|random|all}
        {--include=sitemap,coverage,tracked : Pools to sweep: sitemap, coverage (rows the sitemap no longer carries), tracked (paths Googlebot 404s on)}
        {--urls=* : Inspect these URLs instead of the sitemap (a Console export, tracked 404s)}
        {--source=sitemap : What the rows are: sitemap|console|tracked}
        {--reason= : The Console reason the URLs were exported under, kept on each row}
        {--site= : GSC site URL override}
        {--markdown : Write reports/gsc-inspect-bulk.md}
        {--dry-run : Resolve and prioritize the pool, report how many URLs would be inspected, and call nothing}';

    protected $description = 'Bulk-run GSC URL Inspection against this site\'s URLs and persist coverage state.';

    public function handle(UrlInspectionSweep $sweep): int
    {
        $result = $sweep->run(SweepOptions::fromArray([
            'limit' => $this->option('limit'),
            'sitemap' => $this->option('sitemap'),
            'strategy' => $this->option('strategy'),
            'include' => $this->option('include'),
            'urls' => $this->option('urls'),
            'source' => $this->option('source'),
            'reason' => $this->option('reason'),
            'site' => $this->option('site'),
            'dry_run' => $this->option('dry-run'),
        ]));

        // Plain text, no color/level attached — see SweepResult's docblock.
        foreach ($result->lines as $line) {
            $this->line($line);
        }

        if ($this->option('markdown') && $result->markdown !== '') {
            Storage::disk('local')->put('reports/gsc-inspect-bulk.md', $result->markdown);
            $this->info('Saved: reports/gsc-inspect-bulk.md');
        }

        // Two failure exits: an empty pool, and a site with no Search
        // Console grant (refused by the kit before any quota is spent).
        return in_array('Nothing to inspect.', $result->lines, true) || in_array(UrlInspectionSweep::NOT_CONNECTED_LINE, $result->lines, true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
