<?php

namespace App\Support\Seo;

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use SsSystems\Platform\Seo\Bing\BingCoverageSync;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\Bing\Contracts\
 * BingCoverageWriter — where BingCoverageSync's rows land (kit 0.22.0,
 * 2026-11-10), over the two plain models above. Single-tenant site: no
 * BelongsToSite/site_id, same as App\Support\Seo\BingWriter next to this
 * class. Bound once in AppServiceProvider.
 */
class BingCoverageWriter implements \SsSystems\Platform\Seo\Bing\Contracts\BingCoverageWriter, \SsSystems\Platform\Seo\Bing\Contracts\CountsSitemapUrls
{
    /**
     * whereDate(), never updateOrCreate(['date' => $date, ...]): see
     * BingWriter::upsertDailyTotal()'s docblock for the same MySQL vs.
     * sqlite DATE-truncation reasoning.
     */
    public function upsertCrawlStat(array $row): void
    {
        $row['site_url'] = mb_substr((string) $row['site_url'], 0, 191);

        $existing = BingCrawlStat::whereDate('date', (string) $row['date'])
            ->where('site_url', $row['site_url'])
            ->first();

        if ($existing) {
            $existing->update($row);
        } else {
            BingCrawlStat::create($row);
        }
    }

    /**
     * Keyed on url_hash; updateOrCreate() only fills the keys $row actually
     * carries, so the shown-in-search figures (written first) and the fetch
     * figures (written later, once this URL is inspected) each land on the
     * same row without clobbering the other half.
     */
    public function upsertUrlInfo(array $row): void
    {
        if (isset($row['url'])) {
            $row['url'] = mb_substr((string) $row['url'], 0, 500);
        }

        BingUrlInfo::updateOrCreate(['url_hash' => $row['url_hash']], $row);
    }

    public function urlsToInspect(array $sitemapUrls, int $limit): array
    {
        if ($limit <= 0 || $sitemapUrls === []) {
            return [];
        }

        $hashToUrl = [];

        foreach ($sitemapUrls as $url) {
            $hashToUrl[BingCoverageSync::hash($url)] = $url;
        }

        // Only rows Bing has actually been asked about (inspected_at set)
        // count as "asked" — a URL with no row at all yet is equally
        // "never asked" as one whose row exists but was only ever touched
        // by the shown-figures half of a run.
        $askedAt = BingUrlInfo::query()
            ->whereIn('url_hash', array_keys($hashToUrl))
            ->whereNotNull('inspected_at')
            ->get(['url_hash', 'inspected_at'])
            ->mapWithKeys(fn (BingUrlInfo $row) => [$row->url_hash => (string) $row->inspected_at])
            ->all();

        $neverAsked = [];
        $asked = [];

        foreach ($hashToUrl as $hash => $url) {
            if (array_key_exists($hash, $askedAt)) {
                $asked[$url] = $askedAt[$hash];
            } else {
                $neverAsked[] = $url;
            }
        }

        asort($asked);

        return array_slice(array_merge($neverAsked, array_keys($asked)), 0, $limit);
    }

    public function forgetUrlsNotIn(array $sitemapUrls): int
    {
        $hashes = array_map(static fn (string $url) => BingCoverageSync::hash($url), $sitemapUrls);

        // Scoped to currently-true rows so the returned count is always the
        // number actually flipped, on both MySQL (production) and sqlite
        // (tests) — an UPDATE's affected-row count otherwise differs
        // between the two for rows that already matched the new value.
        return BingUrlInfo::query()
            ->where('in_sitemap', true)
            ->whereNotIn('url_hash', $hashes)
            ->update(['in_sitemap' => false]);
    }

    /**
     * How many stored URLs are in the sitemap today — what the kit's
     * SitemapShrinkGuard compares a new sitemap list against, so a sitemap
     * source that comes back cut in half is refused instead of marking the
     * missing pages as gone.
     */
    public function sitemapUrlCount(): int
    {
        return BingUrlInfo::query()->where('in_sitemap', true)->count();
    }
}
