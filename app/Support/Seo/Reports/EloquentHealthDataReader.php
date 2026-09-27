<?php

namespace App\Support\Seo\Reports;

use App\Support\SeoStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;

/**
 * HealthDataReader over this site's own signals. Ported from
 * dawnsellshomes' identical class — hive.contractors is a SaaS marketing
 * site with none of gsc's/jpeterson-design's local-SEO machinery: no
 * Google Business Profile posting pipeline, no per-image alt-text
 * authoring pipeline, no rank tracker. Every signal below that has no
 * honest source on this site answers the same "not measured" shape the
 * kit's HealthReport already expects from a tenant with nothing to show —
 * never a guessed number.
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

    public function internalLinkAuditLastRunAt(): ?\DateTimeInterface
    {
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

    public function latestRankSnapshots(): array
    {
        // No rank tracker on this site.
        return [];
    }

    /**
     * 'sitemap.xml' is honestly null: this site's sitemap has no static
     * file to stat (rendered on the fly by SitemapController from
     * App\Support\MarketingSitemap, cached in the `marketing-sitemap-xml`
     * cache key) — there is no filesystem mtime to read. 'gsc-sync log' is
     * real: seo:gsc-sync's schedule entry appends its output to this exact
     * path. 'gbp-metrics-sync log' has no equivalent command on this site
     * (see gbpActivity()'s docblock).
     */
    public function freshnessSignals(): array
    {
        return [
            'sitemap.xml' => null,
            'gsc-sync log' => $this->fileMtime(storage_path('logs/seo-gsc-sync.log')),
            'gbp-metrics-sync log' => null,
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
