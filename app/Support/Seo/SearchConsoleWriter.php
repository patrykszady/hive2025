<?php

namespace App\Support\Seo;

use App\Models\GscDailyTotal;
use App\Models\GscQueryMetric;
use App\Models\SeoSyncRun;
use Illuminate\Support\Facades\DB;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\SearchConsoleWriter
 * contract — where SearchConsoleSync::run()'s rows actually land. Ported
 * from dawnsellshomes' identical class; recordSyncRun() writes to
 * App\Models\SeoSyncRun — this site's Search Console credential is a
 * server-held service account, not a per-owner OAuth grant, so there is no
 * token row to piggyback the bookkeeping on.
 */
class SearchConsoleWriter implements \SsSystems\Platform\Seo\SearchConsoleWriter
{
    public function upsertQueryMetric(array $row): string
    {
        $metric = GscQueryMetric::updateOrCreate(['dim_hash' => $row['dim_hash']], $row);

        return $metric->wasRecentlyCreated ? 'inserted' : 'updated';
    }

    /**
     * Not GscDailyTotal::updateOrCreate(['date' => $date, ...]): Eloquent's
     * `date` cast always stores the column as a full 'Y-m-d H:i:s' string,
     * even for a DATE column. MySQL's DATE column truncates that back down
     * on write, so an exact match against the raw 'Y-m-d' string from the
     * API still finds it there — but under the sqlite this suite tests
     * against, nothing truncates it, so a plain-string match always misses
     * and every re-sync would fall through to insert and hit the
     * (date, site_url) unique index. whereDate() normalizes both sides
     * through SQL's DATE() function instead, so the match works under both
     * engines.
     */
    public function upsertDailyTotal(string $date, string $siteUrl, array $totals): void
    {
        $siteUrl = mb_substr($siteUrl, 0, 191);

        $row = GscDailyTotal::whereDate('date', $date)
            ->where('site_url', $siteUrl)
            ->first();

        if ($row) {
            $row->update($totals);
        } else {
            GscDailyTotal::create($totals + ['date' => $date, 'site_url' => $siteUrl]);
        }
    }

    /**
     * Not surfaced in this site's seo/snapshot (no admin card reads it) —
     * this table exists purely so the kit's sync algorithm (which always
     * fetches and writes this dimension) has somewhere to put it. See the
     * gsc_search_appearance_metrics migration's docblock.
     */
    public function upsertSearchAppearance(string $date, string $appearance, array $metrics): void
    {
        DB::table('gsc_search_appearance_metrics')->updateOrInsert(
            ['date' => $date, 'appearance' => $appearance],
            $metrics + ['updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Bookkeeping for one run, kept on its own seo_sync_runs row (see that
     * migration's docblock) rather than an OAuth token's metadata column —
     * read back by PlatformsController::gscStatus() as
     * last_synced_at/last_sync_status/last_sync_error/sync_stale.
     */
    public function recordSyncRun(array $summary): void
    {
        SeoSyncRun::record('search_console', $summary);
    }
}
