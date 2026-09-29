<?php

namespace App\Console\Commands;

use App\Support\Seo\Reports\ReportRefresh;
use Illuminate\Console\Command;

/**
 * Refresh the SEO report library in one pass — see ReportRefresh. The admin's
 * "Refresh all" starts it in the background; the schedule runs it hourly with
 * --stale --automatic so each report is rewritten about once a day.
 */
class SeoReportsRefresh extends Command
{
    protected $signature = 'seo:reports-refresh
        {--stale : Only reports that are not up to date}
        {--automatic : The scheduled pass: skip a report it tried in the last few hours}
        {--keys= : Only these reports, comma-separated (one value, so the kit\'s detached runner can pass it)}';

    protected $description = 'Refresh every available SEO report, one after another';

    public function handle(): int
    {
        $progress = ReportRefresh::run(
            onlyStale: (bool) $this->option('stale'),
            keys: array_values(array_filter(array_map('trim', explode(',', (string) $this->option('keys'))))),
            automatic: (bool) $this->option('automatic'),
        );

        if ($progress === null) {
            $this->info('A refresh is already running.');

            return self::SUCCESS;
        }

        if ($progress['done'] === []) {
            $this->info('Every report is up to date.');

            return self::SUCCESS;
        }

        foreach ($progress['results'] as $key => $status) {
            $this->line("{$key}: {$status}");
        }

        return self::SUCCESS;
    }
}
