<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SsSystems\Platform\Seo\SearchConsoleSync;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\SearchConsoleSyncSkipped;
use SsSystems\Platform\Seo\SearchConsoleWriter;

/**
 * Sync Google Search Console search-analytics data — a thin wrapper around
 * the kit's SsSystems\Platform\Seo\SearchConsoleSync::run(), ported from
 * dawnsellshomes' identical command. This command's only job is reading
 * the CLI options, handing them to run() through this site's
 * SearchConsoleSyncClient (ss-systems/platform-kit's Seo\Google\
 * ServiceAccountSearchConsoleClient, authenticated with the GSC_CREDENTIALS
 * service account rather than an OAuth grant — see
 * App\Providers\AppServiceProvider) and SearchConsoleWriter
 * (App\Support\Seo\SearchConsoleWriter), and printing what happened.
 *
 * No numeric defaults on --days/--lag-days: the kit's
 * SearchConsoleSyncRule::DEFAULT_DAYS/DEFAULT_LAG_DAYS constants are the
 * only source of truth for that window (see routes/console.php's schedule
 * entry, which reads the same constant).
 *
 * A SearchConsoleSyncSkipped (no service-account file, or no property
 * configured) exits SUCCESS with a plain message — not connected is a
 * setup state, not a failure. Any other failure (the service account
 * lacking permission on the property, a network error, ...) is printed as
 * a clear owner-facing message and exits FAILURE — never a stack trace,
 * since SearchConsoleSync/ServiceAccountSearchConsoleClient already turn
 * every Google error into a plain-English reason before it reaches here.
 */
class SeoGscSync extends Command
{
    protected $signature = 'seo:gsc-sync
        {--days= : Number of days back to sync (default: the kit\'s SearchConsoleSyncRule)}
        {--lag-days= : Skip the most recent N days, GSC data lags (default: the kit\'s SearchConsoleSyncRule)}
        {--site= : Override site URL (default from config)}
        {--limit=25000 : Max rows per page}
        {--dry-run}';

    protected $description = 'Sync Google Search Console query/page/country/device metrics';

    public function handle(SearchConsoleSyncClient $client, SearchConsoleWriter $writer): int
    {
        $options = [
            'site_url' => $this->option('site'),
            'limit' => (int) $this->option('limit'),
            'dry_run' => (bool) $this->option('dry-run'),
        ];

        if ($this->option('days') !== null) {
            $options['days'] = (int) $this->option('days');
        }

        if ($this->option('lag-days') !== null) {
            $options['lag_days'] = (int) $this->option('lag-days');
        }

        try {
            $summary = SearchConsoleSync::run($client, $writer, $options);
        } catch (SearchConsoleSyncSkipped $e) {
            $this->info($e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Search Console sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Site: {$summary['site_url']}");
        $this->info("Range: {$summary['window_start']} → {$summary['window_end']}");
        $this->line("Inserted={$summary['inserted']} Updated={$summary['updated']}".($summary['status'] === 'dry-run' ? ' (dry-run)' : ''));
        $this->info("Daily totals upserted: {$summary['daily_totals']} day(s)".($summary['status'] === 'dry-run' ? ' (dry-run)' : ''));
        $this->info("Search-appearance rows: {$summary['appearance_rows']}".($summary['status'] === 'dry-run' ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
