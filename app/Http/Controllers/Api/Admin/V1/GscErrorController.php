<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\RunGscInspectBulkJob;
use App\Models\GscCoverageState;
use App\Support\MarketingSitemap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dedicated GSC Errors screen's endpoints — index (filtered, paginated
 * coverage rows + stats), refresh (queue a full sweep), prune-retired and
 * export. Ported from dawnsellshomes' identical controller.
 *
 * refresh() dispatches RunGscInspectBulkJob; its underlying
 * 'seo:gsc-inspect-bulk' command does real work once GSC_CREDENTIALS +
 * GSC_PROPERTY are set and the service account has permission on the
 * property.
 */
class GscErrorController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);

        $paginator = (clone $query)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->paginate($this->perPage($request, 25));

        $response = $this->paginatedResponse($paginator, fn (GscCoverageState $row) => $this->serializeRow($row));
        $payload = $response->getData(true);
        $payload['stats'] = $this->stats();

        return response()->json($payload);
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            RunGscInspectBulkJob::dispatch();

            return $this->itemResponse(['message' => 'Queued full sitemap inspection in background. Data will update as the job writes new results.']);
        } catch (\Throwable $e) {
            return $this->itemResponse(['message' => 'Failed to queue background refresh: '.$e->getMessage()], 500);
        }
    }

    /**
     * POST seo/gsc-errors/prune-retired — deletes tracked rows for URLs no
     * longer in this site's own URL inventory. Ported from dawnsellshomes'
     * pruneRetired(), adapted to sitemapUrlSet() below (this app's sitemap
     * is rendered on the fly from App\Support\MarketingSitemap, not a
     * static file).
     */
    public function pruneRetired(Request $request): JsonResponse
    {
        $sitemapUrls = $this->sitemapUrlSet();
        if ($sitemapUrls === []) {
            return $this->itemResponse(['deleted' => 0, 'message' => 'Prune skipped: could not read the site\'s URL inventory.']);
        }

        $deleted = 0;
        GscCoverageState::query()
            ->select(['id', 'url'])
            ->chunkById(500, function ($rows) use ($sitemapUrls, &$deleted): void {
                $ids = $rows->filter(fn ($r) => ! isset($sitemapUrls[(string) $r->url]))->pluck('id');
                if ($ids->isNotEmpty()) {
                    $deleted += GscCoverageState::query()->whereIn('id', $ids)->delete();
                }
            });

        $message = $deleted > 0
            ? "Pruned {$deleted} retired URL(s) no longer in the sitemap."
            : 'Nothing to prune — every tracked URL is in the current sitemap.';

        return $this->itemResponse(['deleted' => $deleted, 'message' => $message]);
    }

    /**
     * GET seo/gsc-errors/export — a CSV of the current filtered rows (same
     * filters as index()), capped at 5,000. Ported verbatim from
     * dawnsellshomes' export().
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'gsc-errors-'.now()->format('Ymd-His').'.csv';
        $rows = $this->filteredQuery($request)
            ->orderByRaw('COALESCE(last_changed_at, inspected_at) DESC')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'url', 'path', 'issue', 'verdict', 'coverage_state', 'page_fetch_state',
                'robots_txt_state', 'indexing_state', 'last_crawl_time', 'inspected_at',
                'last_changed_at', 'consecutive_failures', 'user_canonical', 'google_canonical',
            ]);

            foreach ($rows as $row) {
                $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';
                fputcsv($out, [
                    (string) $row->url,
                    (string) $path,
                    $this->classifyIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
                    (string) ($row->verdict ?? 'UNKNOWN'),
                    (string) ($row->coverage_state ?? ''),
                    (string) ($row->page_fetch_state ?? ''),
                    (string) ($row->robots_txt_state ?? ''),
                    (string) ($row->indexing_state ?? ''),
                    optional($row->last_crawl_time)->toDateString(),
                    optional($row->inspected_at)->toDateTimeString(),
                    optional($row->last_changed_at)->toDateTimeString(),
                    (int) ($row->consecutive_failures ?? 0),
                    (string) ($row->user_canonical ?? ''),
                    (string) ($row->google_canonical ?? ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The set of URLs pruneRetired() treats as "still current" — keyed by
     * URL for O(1) lookup, flattened across every locale (the same
     * inventory App\Support\Seo\Inspection\MarketingSitemapSource sweeps).
     *
     * @return array<string, true>
     */
    protected function sitemapUrlSet(): array
    {
        $urls = [];
        foreach (app(MarketingSitemap::class)->entries() as $entry) {
            foreach ($entry['locales'] as $href) {
                $urls[$href] = true;
            }
        }

        return $urls;
    }

    protected function serializeRow(GscCoverageState $row): array
    {
        $path = parse_url((string) $row->url, PHP_URL_PATH) ?: '/';

        return [
            'id' => $row->id,
            'url' => (string) $row->url,
            'path' => (string) $path,
            'issue' => $this->classifyIssue((string) $row->coverage_state, (string) $row->page_fetch_state, (string) $row->verdict),
            'verdict' => (string) ($row->verdict ?? 'UNKNOWN'),
            'coverage_state' => (string) ($row->coverage_state ?? ''),
            'page_fetch_state' => (string) ($row->page_fetch_state ?? ''),
            'robots_txt_state' => (string) ($row->robots_txt_state ?? ''),
            'indexing_state' => (string) ($row->indexing_state ?? ''),
            'last_crawl_time' => $row->last_crawl_time?->toDateString(),
            'inspected_at' => $row->inspected_at?->toIso8601String(),
            'last_changed_at' => $row->last_changed_at?->toIso8601String(),
            'consecutive_failures' => (int) ($row->consecutive_failures ?? 0),
        ];
    }

    /** @return array{tracked:int,problem:int,pass:int,latest_inspected:?string} */
    protected function stats(): array
    {
        $tracked = GscCoverageState::query()->count();
        $problem = GscCoverageState::query()->where(function ($q) {
            $q->where('verdict', '!=', 'PASS')
                ->orWhereNull('verdict')
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);
        })->count();

        return [
            'tracked' => (int) $tracked,
            'problem' => (int) $problem,
            'pass' => max(0, (int) $tracked - (int) $problem),
            'latest_inspected' => ($latest = GscCoverageState::query()->max('inspected_at'))
                ? Carbon::parse($latest)->diffForHumans()
                : null,
        ];
    }

    protected function filteredQuery(Request $request): Builder
    {
        $scope = $request->string('scope', 'problems')->toString();
        $search = $request->string('search', '')->toString();
        $verdictFilter = $request->string('verdict', 'all')->toString();
        $issueFilter = $request->string('issue', 'all')->toString();

        $query = GscCoverageState::query();

        if ($scope === 'problems') {
            $query->where(function ($q) {
                $q->where('verdict', '!=', 'PASS')
                    ->orWhereNull('verdict')
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%server%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%not found%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%redirect%']);
            });
        }

        if ($search !== '') {
            $term = '%'.str_replace('%', '\\%', strtolower(trim($search))).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(url) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(verdict, "")) like ?', [$term]);
            });
        }

        if ($verdictFilter !== 'all') {
            if ($verdictFilter === 'unknown') {
                $query->whereNull('verdict');
            } else {
                $query->where('verdict', strtoupper($verdictFilter));
            }
        }

        if ($issueFilter !== 'all') {
            $query->where(fn ($q) => $this->applyIssueFilter($q, $issueFilter));
        }

        return $query;
    }

    protected function applyIssueFilter(Builder $query, string $issueFilter): void
    {
        if ($issueFilter === 'blocked') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%robots%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%robots%']);
            });

            return;
        }

        if ($issueFilter === 'not_indexed') {
            $query->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%']);

            return;
        }

        if ($issueFilter === 'duplicate') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%canonical%']);
            });

            return;
        }

        if ($issueFilter === 'soft_404') {
            $query->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%']);

            return;
        }

        if ($issueFilter === 'fetch_error') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%server error%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%server%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%not found%'])
                    ->orWhereRaw('LOWER(COALESCE(page_fetch_state, "")) like ?', ['%redirect%']);
            });

            return;
        }

        if ($issueFilter === 'other') {
            $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%forbidden%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%not indexed%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%duplicate%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%soft 404%'])
                    ->whereRaw('LOWER(COALESCE(coverage_state, "")) not like ?', ['%canonical%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%server%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%not found%'])
                    ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%redirect%']);
            });
        }
    }

    protected function classifyIssue(string $coverageState, string $pageFetchState, string $verdict): string
    {
        $text = strtolower(trim($coverageState.' '.$pageFetchState));

        if ($text === '' && strtoupper($verdict) === 'PASS') {
            return 'Indexed';
        }
        if (str_contains($text, 'forbidden') || str_contains($text, 'robots')) {
            return 'Blocked';
        }
        if (str_contains($text, 'not indexed')) {
            return 'Not indexed';
        }
        if (str_contains($text, 'duplicate') || str_contains($text, 'canonical')) {
            return 'Duplicate/canonical';
        }
        if (str_contains($text, 'soft 404')) {
            return 'Soft 404';
        }
        if (str_contains($text, 'server') || str_contains($text, 'not found') || str_contains($text, 'redirect')) {
            return 'Fetch error';
        }

        return strtoupper($verdict) === 'PASS' ? 'Indexed' : 'Other';
    }
}
