<?php

namespace App\Support\Seo\Reports;

use App\Models\BingDailyTotal;
use App\Models\SeoSyncRun;
use App\Support\SeoStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;
use SsSystems\Platform\Seo\Rankings\RankSnapshotStore;

/**
 * HealthDataReader over this site's own signals. Ported from
 * dawnsellshomes' identical class — hive.contractors is a SaaS marketing
 * site with none of gsc's/jpeterson-design's local-SEO machinery: no
 * Google Business Profile posting pipeline, no per-image alt-text
 * authoring pipeline. Every signal below that has no honest source on this
 * site answers the same "not measured" shape the kit's HealthReport
 * already expects from a tenant with nothing to show — never a guessed
 * number. Rankings ARE real (see latestRankSnapshots() below) — the kit's
 * seo:track-rankings writes seo_rank_snapshots since 2026-11-01.
 *
 * The health-score ledger is stored the same way the other kit sites'
 * is: reports/health-history.json under SeoStorage — a date => score map,
 * kept sorted and pruned to 120 entries by the kit report itself; this
 * class only reads/writes whatever it is given.
 */
class EloquentHealthDataReader implements HealthDataReader
{
    private const LEDGER_PATH = 'reports/health-history.json';

    /** No per-image alt-text authoring pipeline on this marketing site — honestly "nothing to measure", not a fabricated 100%. */
    public function imageAltCoverage(): array
    {
        return ['total' => 0, 'with_alt' => 0];
    }

    /**
     * When the internal-link report last ran: its saved report file (written
     * by every run, scheduled or started from the admin), the log only as a
     * fallback — a run started by hand never writes the schedule's log.
     */
    public function internalLinkAuditLastRunAt(): ?\DateTimeInterface
    {
        $disk = Storage::disk('local');
        $report = SeoStorage::path('reports/internal-link-suggest.md');

        if ($disk->exists($report)) {
            return Carbon::createFromTimestamp($disk->lastModified($report));
        }

        return $this->fileMtime(storage_path('logs/seo-internal-link-suggest.log'));
    }

    /** No Google Business Profile posting/photo pipeline on this site at all — honestly "never measured", not "neglected". */
    public function gbpActivity(): array
    {
        return [
            'posts_last_7' => 0,
            'posts_last_30' => 0,
            'ever_posted' => false,
            'photos_last_90' => null,
            'ever_uploaded' => false,
        ];
    }

    /**
     * The kit's seo:track-rankings replaces this site's own retired
     * seo:rank-check/DataForSeoService pair: the latest row per tracked
     * query in seo_rank_snapshots, 'google' engine only ('gsc' rows are
     * Search Console's own impression-weighted position — a different kind
     * of fact the health score's rankings measure never wanted mixed in
     * with a real SERP check). RankSnapshotStore autowires with no tenant
     * column/id, same as every other single-tenant site. Empty until the
     * first tracked run.
     */
    public function latestRankSnapshots(): array
    {
        return collect(app(RankSnapshotStore::class)->latestRows())
            ->where('engine', 'google')
            ->map(fn (array $row): array => ['engine' => 'google', 'position' => $row['position'] !== null ? (float) $row['position'] : null])
            ->values()
            ->all();
    }

    /**
     * How recently each pipeline this site actually has last delivered:
     * the sitemap is built on every request from the live routes
     * (App\Support\MarketingSitemap), so it is always current; Search
     * Console from its last successful sync run; Bing from its newest daily
     * total. No Google Business Profile line: this site has no listing
     * pipeline, and the health score would count a missing one as stale.
     */
    public function freshnessSignals(): array
    {
        $gsc = SeoSyncRun::summary('search_console') ?? [];
        $gscAt = ($gsc['status'] ?? null) === 'ok' && ! empty($gsc['finished_at']) ? Carbon::parse($gsc['finished_at']) : null;
        $bingAt = BingDailyTotal::query()->max('updated_at');

        return [
            'sitemap.xml' => now(),
            'search-console sync' => $gscAt,
            'bing sync' => $bingAt ? Carbon::parse($bingAt) : null,
        ];
    }

    public function healthLedger(): array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path(self::LEDGER_PATH);

        if (! $disk->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function putHealthLedger(array $ledger): void
    {
        Storage::disk('local')->put(
            SeoStorage::path(self::LEDGER_PATH),
            json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function fileMtime(string $path): ?Carbon
    {
        return is_file($path) ? Carbon::createFromTimestamp(filemtime($path)) : null;
    }
}
