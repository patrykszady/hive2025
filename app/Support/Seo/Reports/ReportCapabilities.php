<?php

namespace App\Support\Seo\Reports;

use SsSystems\Platform\Reports\ReportRegistry;

/**
 * The explicit, checkable list of kit report capabilities THIS site has
 * bound — ported from dawnsellshomes' identical class. A capability not in
 * provided() is one no adapter here can answer honestly:
 *
 *   - psi_snapshots: no page-speed measurements are collected on this site
 *     (no PsiSnapshotReader binding) — CwvTemplateReport is unavailable.
 *   - clarity_metrics: no Microsoft Clarity integration at all — no
 *     ClarityMetricsReader binding — ClarityHealthReport is unavailable.
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
        'clarity_metrics' => 'Needs visitor behaviour data this site does not collect.',
        'area_catalog' => 'Needs the service area pages this site does not have.',
        'site_identity' => 'Needs the business details this site has not configured.',
    ];

    private const DEFAULT_REASON = 'Not available on this site yet.';

    /** @return list<string> */
    public static function provided(): array
    {
        return [
            'query_metrics',
            'page_fetcher',
            'site_catalog',
            'site_identity',
            'health_data',
            'cache',
        ];
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
