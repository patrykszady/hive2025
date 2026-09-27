<?php

namespace App\Support\Seo;

use App\Models\BingDailyTotal;
use App\Models\BingTrafficStat;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\Bing\BingWriter —
 * where BingSync::run()'s rows land. Ported verbatim from dawnsellshomes'
 * identical class. Unlike Search Console, GET platforms/status's bing
 * block carries no last_synced_at/last_sync_status (task spec: just
 * configured/source), so there is no run bookkeeping to write here.
 */
class BingWriter implements \SsSystems\Platform\Seo\Bing\BingWriter
{
    public function upsertQueryStat(array $row): string
    {
        $stat = BingTrafficStat::updateOrCreate(['dim_hash' => $row['dim_hash']], $row);

        return $stat->wasRecentlyCreated ? 'inserted' : 'updated';
    }

    /**
     * whereDate(), never updateOrCreate(['date' => $date, ...]): see
     * SearchConsoleWriter::upsertDailyTotal()'s docblock — same MySQL vs.
     * sqlite DATE-truncation reasoning applies here.
     */
    public function upsertDailyTotal(string $date, string $siteUrl, array $totals): void
    {
        $siteUrl = mb_substr($siteUrl, 0, 191);
        $row = BingDailyTotal::whereDate('date', $date)->where('site_url', $siteUrl)->first();

        if ($row) {
            $row->update($totals);
        } else {
            BingDailyTotal::create($totals + ['date' => $date, 'site_url' => $siteUrl]);
        }
    }
}
