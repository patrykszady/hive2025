<?php

namespace App\Support\Seo\Reports;

use App\Models\GscQueryMetric;
use SsSystems\Platform\Reports\Contracts\QueryMetricsReader;

/**
 * QueryMetricsReader over this site's own gsc_query_metrics table (filled by
 * the real seo:gsc-sync — SsSystems\Platform\Seo\SearchConsoleSync via
 * App\Support\Seo\SearchConsoleWriter). Ported verbatim from
 * dawnsellshomes' identical class — same aggregations, single-tenant (no
 * site scoping needed here).
 */
class EloquentQueryMetricsReader implements QueryMetricsReader
{
    public function pageMetrics(string $from, string $to): array
    {
        return GscQueryMetric::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as weighted_pos')
            ->groupBy('page')
            ->get()
            ->map(fn ($r) => [
                'page' => $r->page,
                'impressions' => (int) $r->impr,
                'clicks' => (int) $r->clicks,
                'position' => $r->impr > 0 ? round(((float) $r->weighted_pos) / $r->impr, 2) : null,
            ])
            ->all();
    }

    public function queryPageMetrics(string $from, string $to, int $minImpressions): array
    {
        return GscQueryMetric::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('query, page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as weighted_pos')
            ->groupBy('query', 'page')
            ->havingRaw('SUM(impressions) >= ?', [$minImpressions])
            ->get()
            ->map(fn ($r) => [
                'query' => (string) $r->query,
                'page' => (string) ($r->page ?? ''),
                'impressions' => (int) $r->impr,
                'clicks' => (int) $r->clicks,
                // Unrounded, exactly like SeoContentGap — the report itself
                // rounds after receiving it.
                'position' => (int) $r->impr > 0 ? ((float) $r->weighted_pos) / $r->impr : 0.0,
            ])
            ->all();
    }
}
