<?php

namespace App\Console\Commands;

use App\Models\SeoSyncRun;
use App\Services\DataForSeoService;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Console\Command;

/**
 * The live "where you rank" check, run on a schedule and saved — never
 * inside a page request. DataForSEO's live SERP answers in several seconds
 * per query (three queries took 21.6s on 2026-09-27), longer than the
 * central admin waits for the SEO snapshot (15s), so doing it on a cold
 * cache made the whole SEO screen read "Couldn't reach the site's SEO API"
 * every half hour — and billed a check each time. The snapshot now reads
 * the last saved result (SeoSnapshotController::rankingsSnapshot()).
 *
 * A failed check keeps the last good result on screen and records the
 * error beside it, rather than blanking the panel.
 */
class SeoRankCheck extends Command
{
    protected $signature = 'seo:rank-check';

    protected $description = 'Check where this site ranks for its tracked searches (DataForSEO) and save the result for the SEO screen.';

    public const SYNC_KEY = 'dataforseo_rankings';

    public function handle(DataForSeoSettings $settings, DataForSeoService $service): int
    {
        if (! $settings->isConfigured()) {
            $this->info('DataForSEO is not connected for this site — nothing to check.');

            return self::SUCCESS;
        }

        $previous = SeoSyncRun::summary(self::SYNC_KEY) ?? [];
        $current = $service->checkRankings();

        if ($current === null) {
            SeoSyncRun::record(self::SYNC_KEY, array_merge($previous, [
                'last_attempt_at' => now()->toIso8601String(),
                'last_error' => 'The ranking check did not complete.',
            ]));
            $this->warn('The ranking check did not complete; the last saved result stays in place.');

            return self::FAILURE;
        }

        SeoSyncRun::record(self::SYNC_KEY, [
            'current' => $current,
            'checked_at' => now()->toIso8601String(),
            'last_attempt_at' => now()->toIso8601String(),
            'last_error' => null,
        ]);

        $this->info(sprintf(
            'Checked %d searches: %d in the top 3, %d in the top 10, %d in the top 20.',
            $current['tracked'] ?? 0, $current['top3'] ?? 0, $current['top10'] ?? 0, $current['top20'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
