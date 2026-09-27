<?php

namespace App\Services;

use App\Support\Seo\DataForSeoSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO's live SERP check — the SEO screen's "Where you rank" panel
 * reads whatever `rankings.live_serp` a site's seo/snapshot sends (see
 * App\Http\Controllers\Api\Admin\V1\SeoSnapshotController::
 * rankingsSnapshot()); this is what actually runs the search, once
 * credentials are provisioned (App\Support\Seo\DataForSeoSettings). Unlike
 * gs.construction's much larger seo:track-rankings (which derives position
 * from synced Search Console impressions for a config-driven query list),
 * this is a genuinely LIVE, on-demand check against a small fixed set of
 * queries — this app has no Search Console query history worth deriving a
 * position from, and no per-tenant query-list config, so the honest thing
 * is a real SERP request each time, not a fabricated substitute.
 *
 * Any failure (missing credentials, a non-2xx response, a network error)
 * returns null from checkRankings() rather than a partial/zero result —
 * "unavailable" must never look like "ranked nowhere".
 */
class DataForSeoService
{
    protected const SERP_LIVE_URL = 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced';

    /** Google's "United States" location code, DataForSEO's own enum. */
    protected const LOCATION_CODE_US = 2840;

    /** How far down the results to look for this site's own domain. */
    protected const DEPTH = 20;

    /**
     * A small, fixed set of queries relevant to what hive.contractors
     * sells — there is no per-tenant keyword config on this single-tenant
     * app, and inventing a large tracked-query list would just multiply
     * DataForSEO's per-query cost for no real benefit at this size.
     *
     * @var list<string>
     */
    public const TRACKED_QUERIES = [
        'construction management software',
        'contractor project management software',
        'hive contractors',
    ];

    public function __construct(private readonly DataForSeoSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * One live SERP check per tracked query. Null when not configured or
     * when ANY query's request fails outright — a partial result would
     * misrepresent "tracked" as smaller than it really is.
     *
     * Also returns each tracked search's own position (`queries`), which the
     * SEO health score's rankings measure reads (EloquentHealthDataReader::
     * latestRankSnapshots()).
     *
     * @return array{tracked:int,top3:int,top10:int,top20:int,below20:int,queries:list<array{query:string,position:?int}>}|null
     */
    public function checkRankings(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $domain = $this->siteDomain();
        if ($domain === '') {
            return null;
        }

        $positions = [];
        $queries = [];
        foreach (self::TRACKED_QUERIES as $query) {
            $position = $this->livePosition($query, $domain);
            if ($position === false) {
                return null;
            }
            $positions[] = $position;
            $queries[] = ['query' => $query, 'position' => $position];
        }

        return $this->bucket($positions) + ['queries' => $queries];
    }

    /**
     * @return int|null|false Position (1-based), null when not found within
     *                         self::DEPTH results, false on any request
     *                         failure.
     */
    protected function livePosition(string $query, string $domain): int|null|false
    {
        try {
            $response = Http::withBasicAuth((string) $this->settings->login(), (string) $this->settings->password())
                ->acceptJson()
                ->timeout(30)
                ->post(self::SERP_LIVE_URL, [[
                    'keyword' => $query,
                    'location_code' => self::LOCATION_CODE_US,
                    'language_code' => 'en',
                    'device' => 'desktop',
                    'depth' => self::DEPTH,
                ]]);
        } catch (\Throwable $e) {
            Log::warning('DataForSEO SERP check failed', ['query' => $query, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('DataForSEO SERP check failed', ['query' => $query, 'status' => $response->status()]);

            return false;
        }

        $items = (array) data_get($response->json(), 'tasks.0.result.0.items', []);

        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'organic') {
                continue;
            }

            $link = (string) ($item['url'] ?? $item['domain'] ?? '');
            if ($link !== '' && str_contains($link, $domain)) {
                return (int) ($item['rank_absolute'] ?? $item['rank_group'] ?? 0) ?: null;
            }
        }

        return null;
    }

    /** @param  list<int|null>  $positions */
    protected function bucket(array $positions): array
    {
        $tracked = count($positions);
        $top3 = count(array_filter($positions, fn (?int $p) => $p !== null && $p <= 3));
        $top10 = count(array_filter($positions, fn (?int $p) => $p !== null && $p <= 10));
        $top20 = count(array_filter($positions, fn (?int $p) => $p !== null && $p <= 20));

        return [
            'tracked' => $tracked,
            'top3' => $top3,
            'top10' => $top10,
            'top20' => $top20,
            'below20' => max(0, $tracked - $top20),
        ];
    }

    protected function siteDomain(): string
    {
        $url = (string) config('app.marketing_url', config('app.url'));
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
    }
}
