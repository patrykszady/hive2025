<?php

namespace App\Support\Seo\Reports;

use App\Support\Seo\ClaritySettings;
use App\Support\Seo\PsiSettings;
use SsSystems\Platform\Reports\ReportRegistry;

/**
 * The explicit, checkable list of kit report capabilities THIS site has
 * bound — ported from dawnsellshomes' identical class. A capability not in
 * provided() is one no adapter here can answer honestly:
 *
 *   - psi_snapshots: App\Support\Seo\Reports\EloquentPsiSnapshotReader is
 *     always bound (so a direct `php artisan seo:cwv-template` resolves),
 *     but only listed here once App\Support\Seo\PsiSettings::usingOwnKey()
 *     — an owner has saved a PageSpeed key from the SEO screen's Connect
 *     Services modal. App\Console\Commands\SeoPsiSync (the only writer of
 *     psi_snapshots rows) waits for that same key before it runs, so
 *     "available" and "has real data to show" turn on together rather
 *     than the report claiming availability with an empty table under it.
 *   - clarity_metrics: same shape — App\Support\Seo\Reports\
 *     ClaritySettingsMetricsReader is always bound, but only listed here
 *     once App\Support\Seo\ClaritySettings::isConfigured() (both the
 *     project id and the API token are saved).
 *   - area_catalog: hive.contractors has no per-city/per-area landing
 *     pages at all (see App\Support\Seo\Reports\EmptyAreaCatalog's
 *     docblock) — inventing a "content_complete" heuristic for a page
 *     shape that doesn't exist would be a fabricated signal, not an honest
 *     one, so EmptyAreaCatalog is bound (the container can still resolve
 *     HealthReport/AreaPagesAuditReport when a command is run directly)
 *     but deliberately left OUT of provided() — AreaPagesAuditReport reads
 *     "not available on this site" here rather than silently treated as a
 *     real zero.
 *
 * ReportRegistry::get($key)['requires'] is the authoritative list of what a
 * report needs; this class only says which of those keys THIS site can
 * answer.
 */
class ReportCapabilities
{
    /**
     * Reasons are owner-facing — no vendor or pipeline names (ss-systems'
     * own rule, see SS Systems CLAUDE.md's "No vendor or pipeline name
     * outside a Details accordion").
     */
    private const REASONS = [
        'query_metrics' => 'Needs search results data this site has not collected yet.',
        'psi_snapshots' => 'Needs page speed measurements this site does not collect yet.',
        'clarity_metrics' => 'Needs visitor behaviour data. Connect it under Connect Services.',
        'area_catalog' => 'Needs the service area pages this site does not have.',
        'site_identity' => 'Needs the business details this site has not configured.',
    ];

    private const DEFAULT_REASON = 'Not available on this site yet.';

    /** @return list<string> */
    public static function provided(): array
    {
        $capabilities = [
            'query_metrics',
            'page_fetcher',
            'site_catalog',
            'site_identity',
            'health_data',
            'cache',
        ];

        if (app(ClaritySettings::class)->isConfigured()) {
            $capabilities[] = 'clarity_metrics';
        }

        if (app(PsiSettings::class)->usingOwnKey()) {
            $capabilities[] = 'psi_snapshots';
        }

        return $capabilities;
    }

    /** @return array{available: bool, missing: list<string>, reason: ?string} */
    public static function availability(string $key): array
    {
        $requires = ReportRegistry::get($key)['requires'];
        $missing = array_values(array_diff($requires, self::provided()));

        if ($missing === []) {
            return ['available' => true, 'missing' => [], 'reason' => null];
        }

        return [
            'available' => false,
            'missing' => $missing,
            'reason' => self::REASONS[$missing[0]] ?? self::DEFAULT_REASON,
        ];
    }
}
