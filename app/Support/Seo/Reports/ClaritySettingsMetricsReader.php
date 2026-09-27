<?php

namespace App\Support\Seo\Reports;

use App\Services\MicrosoftClarityService;
use App\Support\Seo\ClaritySettings;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;

/**
 * ClarityMetricsReader over this site's real Clarity integration
 * (App\Support\Seo\ClaritySettings/App\Services\MicrosoftClarityService)
 * for the "is it configured, is the API reachable" half, and honest
 * empty/zero answers for the "stored rows" half and the JS-error beacon —
 * this site has no clarity_daily_metrics table (no sync has ever written
 * one) and no client-error beacon table at all (see
 * App\Http\Middleware\CachePublicPage's docblock — the CSRF-rewrite
 * problem it solves is the closest thing to a public JS beacon this app
 * has, and it is unrelated), so both report that truthfully rather than
 * reading a table that isn't there. Ported from jpeterson-design's
 * identical class.
 */
class ClaritySettingsMetricsReader implements ClarityMetricsReader
{
    private ?string $lastError = null;

    public function __construct(
        private readonly ClaritySettings $settings,
        private readonly MicrosoftClarityService $clarity,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    public function projectId(): ?string
    {
        return $this->settings->projectId();
    }

    public function fetchLiveSnapshot(): ?array
    {
        $rows = $this->clarity->fetchDailyMetrics(MicrosoftClarityService::MAX_DAYS);

        if ($rows === null || $rows === []) {
            $this->lastError = $this->clarity->getLastError();

            return null;
        }

        return $rows[0];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function latestStoredMetric(): ?array
    {
        // No clarity_daily_metrics table on this site — see class docblock.
        return null;
    }

    public function storedRowCount(): int
    {
        return 0;
    }

    public function baselineMetrics(string $beforeDate, int $limit): array
    {
        return [];
    }

    public function beaconErrorCount(\DateTimeInterface $since): int
    {
        // No on-site JS-error beacon table — see class docblock.
        return 0;
    }
}
