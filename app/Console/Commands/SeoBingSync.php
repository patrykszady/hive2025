<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SsSystems\Platform\Seo\Bing\BingSync;
use SsSystems\Platform\Seo\Bing\BingSyncSkipped;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter;

/**
 * Sync Bing Webmaster Tools performance data — the kit's BingSync over this
 * site's client (BING_WMT_KEY or the admin-saved key, App\Support\Seo\
 * BingSettings) and this site's BingWriter. Ported from dawnsellshomes'
 * identical command; persists to bing_traffic_stats/bing_daily_totals so
 * the SEO snapshot's 'search' block can show a Bing channel alongside
 * Google.
 */
class SeoBingSync extends Command
{
    protected $signature = 'seo:bing-sync {--dry-run : Fetch and show a sample without writing}';

    protected $description = 'Sync Bing Webmaster Tools query stats and daily totals (API-key auth, no-op without a key).';

    public function handle(BingWebmasterClient $client, BingWriter $writer): int
    {
        try {
            $summary = BingSync::run($client, $writer, ['dry_run' => (bool) $this->option('dry-run')]);
        } catch (BingSyncSkipped $e) {
            $this->info($e->getMessage());

            return self::SUCCESS;
        }

        if ($summary['status'] === 'failed') {
            $this->error('Bing fetch failed: '.$summary['error']);

            return self::FAILURE;
        }

        $dry = $summary['status'] === 'dry-run' ? ' (dry-run)' : '';
        $this->info("Site: {$summary['site_url']}");
        $this->info("Fetched {$summary['fetched']} rows from Bing WMT");
        foreach ($summary['sample'] as $row) {
            $this->line(json_encode($row));
        }
        $this->line("Inserted={$summary['inserted']} Updated={$summary['updated']} Skipped={$summary['skipped_rows']}{$dry}");
        if ($summary['daily_totals_error'] !== null) {
            $this->warn('Daily-totals fetch failed: '.$summary['daily_totals_error']);
        } else {
            $this->info("Daily totals upserted: {$summary['daily_totals']} day(s){$dry}");
        }

        return self::SUCCESS;
    }
}
