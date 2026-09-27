<?php

namespace App\Support\Seo\Inspection;

use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
use Illuminate\Support\Carbon;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore;

/**
 * CoverageStore over this site's own gsc_coverage_states/
 * gsc_rich_result_issues tables. Ported from dawnsellshomes' identical
 * class. recordHistory() is a documented no-op: this site has no
 * gsc_coverage_state_history table, so there is nowhere to put a history
 * row — nothing is lost that a table could hold, it simply is not kept.
 */
class EloquentCoverageStore implements CoverageStore
{
    public function knownUrls(): array
    {
        return GscCoverageState::query()->orderBy('inspected_at')->pluck('url')->all();
    }

    public function knownUrlsAmong(array $urls): array
    {
        return GscCoverageState::query()
            ->whereIn('url', $urls)
            ->orderBy('inspected_at')
            ->pluck('url')
            ->all();
    }

    public function find(string $url): ?array
    {
        $row = GscCoverageState::query()->where('url', $url)->first();

        if (! $row) {
            return null;
        }

        return [
            'url' => $row->url,
            'source' => $row->source,
            'console_reason' => $row->console_reason,
            'verdict' => $row->verdict,
            'coverage_state' => $row->coverage_state,
            'robots_txt_state' => $row->robots_txt_state,
            'indexing_state' => $row->indexing_state,
            'page_fetch_state' => $row->page_fetch_state,
            'sitemap_url' => $row->sitemap_url,
            'last_crawl_time' => $row->last_crawl_time?->toIso8601String(),
            'user_canonical' => $row->user_canonical,
            'google_canonical' => $row->google_canonical,
            'inspected_at' => $row->inspected_at?->toIso8601String(),
            'last_changed_at' => $row->last_changed_at?->toIso8601String(),
            'consecutive_failures' => (int) $row->consecutive_failures,
        ];
    }

    public function upsert(array $row): void
    {
        GscCoverageState::query()->updateOrCreate(
            ['url' => $row['url']],
            [
                'source' => $row['source'],
                'console_reason' => $row['console_reason'],
                'verdict' => $row['verdict'],
                'coverage_state' => $row['coverage_state'],
                'robots_txt_state' => $row['robots_txt_state'],
                'indexing_state' => $row['indexing_state'],
                'page_fetch_state' => $row['page_fetch_state'],
                'sitemap_url' => $row['sitemap_url'],
                'last_crawl_time' => $row['last_crawl_time'],
                'user_canonical' => $row['user_canonical'],
                'google_canonical' => $row['google_canonical'],
                'inspected_at' => $row['inspected_at'],
                'last_changed_at' => $row['last_changed_at'],
                'consecutive_failures' => $row['consecutive_failures'],
            ]
        );
    }

    public function recordHistory(array $row): void
    {
        // No-op — see this class's docblock.
    }

    public function replaceRichResultIssues(string $url, array $issues): void
    {
        GscRichResultIssue::query()->where('url', $url)->delete();

        if ($issues === []) {
            return;
        }

        $now = Carbon::now();

        GscRichResultIssue::query()->insert(array_map(fn (array $issue) => [
            'url' => $url,
            'rich_result_type' => $issue['rich_result_type'],
            'issue_severity' => $issue['issue_severity'],
            'issue_type' => $issue['issue_type'],
            'issue_message' => $issue['issue_message'],
            'verdict' => $issue['verdict'],
            'inspected_at' => $issue['inspected_at'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $issues));
    }

    public function verdictCoverageTotals(): array
    {
        return GscCoverageState::query()
            ->selectRaw('verdict, coverage_state, count(*) as n')
            ->groupBy('verdict', 'coverage_state')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'verdict' => $r->verdict,
                'coverage_state' => $r->coverage_state,
                'n' => (int) $r->n,
            ])
            ->all();
    }
}
