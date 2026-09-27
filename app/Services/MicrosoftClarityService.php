<?php

namespace App\Services;

use App\Support\Seo\ClaritySettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Clarity Data Export API wrapper — trimmed from jpeterson-
 * design's/gs.construction's identical service: this app has no
 * per-page Clarity dashboard, so only the site-wide fetchDailyMetrics()
 * that App\Support\Seo\Reports\ClaritySettingsMetricsReader needs for the
 * kit's clarity-health report is ported (their fetchPageMetrics() and its
 * per-page normalisation helpers are not — nothing here consumes them).
 * Reads its credentials through App\Support\Seo\ClaritySettings rather
 * than config() directly.
 */
class MicrosoftClarityService
{
    protected ?string $lastError = null;

    /** Clarity Data Export API supports only the last 1-3 days. */
    public const MAX_DAYS = 3;

    public function isConfigured(): bool
    {
        return app(ClaritySettings::class)->isConfigured();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch Clarity dashboard export metrics. Returns a single normalized
     * snapshot row: ['date' => 'YYYY-MM-DD', 'sessions' => int, ...].
     *
     * @return list<array{date:string,sessions:int,users:int,pageviews:int,scroll_depth:float,active_time_seconds:int,bounce_rate:float,dead_clicks:int,rage_clicks:int,quickbacks:int,script_errors:int,error_clicks:int}>|null
     */
    public function fetchDailyMetrics(int $days = 28): ?array
    {
        $days = max(1, min(self::MAX_DAYS, $days));

        $payload = $this->requestInsights(['numOfDays' => $days, 'dimension1' => 'OS']);
        if ($payload === null) {
            return null;
        }

        $summary = [
            'date' => now()->toDateString(),
            'sessions' => 0,
            'users' => 0,
            'pageviews' => 0,
            'scroll_depth' => 0.0,
            'active_time_seconds' => 0,
            'bounce_rate' => 0.0,
            'dead_clicks' => 0,
            'rage_clicks' => 0,
            'quickbacks' => 0,
            'script_errors' => 0,
            'error_clicks' => 0,
        ];

        $scrollWeightedSum = 0.0;
        $scrollWeightSessions = 0;
        $ppsWeightedSum = 0.0;
        $ppsWeightSessions = 0;

        $sessionsByOs = [];
        foreach ($payload as $g) {
            if (is_array($g) && strcasecmp((string) ($g['metricName'] ?? ''), 'Traffic') === 0) {
                foreach ($g['information'] ?? [] as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $os = (string) ($r['OS'] ?? $r['Browser'] ?? '');
                    $sessionsByOs[$os] = $this->toInt($r, ['totalSessionCount', 'sessionCount', 'sessions']);
                }
                break;
            }
        }

        foreach ($payload as $metricGroup) {
            if (! is_array($metricGroup)) {
                continue;
            }

            $metricName = (string) ($metricGroup['metricName'] ?? '');
            $infoRows = $metricGroup['information'] ?? [];
            if (! is_array($infoRows)) {
                continue;
            }

            foreach ($infoRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $os = (string) ($row['OS'] ?? $row['Browser'] ?? '');
                $rowSessions = $sessionsByOs[$os] ?? $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);

                switch (true) {
                    case strcasecmp($metricName, 'Traffic') === 0:
                        $sessions = $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);
                        $summary['sessions'] += $sessions;
                        $summary['users'] += $this->toInt($row, ['distinctUserCount', 'distantUserCount', 'uniqueUsers', 'users']);
                        $pps = $this->toFloat($row, ['pagesPerSessionPercentage', 'pagesPerSession']);
                        if ($sessions > 0 && $pps > 0) {
                            $ppsWeightedSum += $pps * $sessions;
                            $ppsWeightSessions += $sessions;
                            $summary['pageviews'] += (int) round($pps * $sessions);
                        }
                        break;

                    case strcasecmp($metricName, 'EngagementTime') === 0:
                        $summary['active_time_seconds'] += $this->toInt($row, ['activeTime', 'engagementTime', 'activeTimeSeconds']);
                        break;

                    case strcasecmp($metricName, 'ScrollDepth') === 0:
                        $depth = $this->toFloat($row, ['averageScrollDepth', 'ScrollDepth', 'scrollDepth']);
                        if ($depth > 0 && $rowSessions > 0) {
                            $scrollWeightedSum += $depth * $rowSessions;
                            $scrollWeightSessions += $rowSessions;
                        }
                        break;

                    case strcasecmp($metricName, 'DeadClickCount') === 0:
                        $summary['dead_clicks'] += $this->toInt($row, ['subTotal', 'deadClickCount', 'deadClicks']);
                        break;

                    case strcasecmp($metricName, 'RageClickCount') === 0:
                        $summary['rage_clicks'] += $this->toInt($row, ['subTotal', 'rageClickCount', 'rageClicks']);
                        break;

                    case strcasecmp($metricName, 'QuickbackClick') === 0:
                        $summary['quickbacks'] += $this->toInt($row, ['subTotal', 'quickbackClick', 'quickbacks']);
                        break;

                    case strcasecmp($metricName, 'ScriptErrorCount') === 0:
                        $summary['script_errors'] += $this->toInt($row, ['subTotal', 'scriptErrorCount', 'scriptErrors']);
                        break;

                    case strcasecmp($metricName, 'ErrorClickCount') === 0:
                        $summary['error_clicks'] += $this->toInt($row, ['subTotal', 'errorClickCount', 'errorClicks']);
                        break;
                }
            }
        }

        if ($scrollWeightSessions > 0) {
            $summary['scroll_depth'] = round($scrollWeightedSum / $scrollWeightSessions, 4);
        }

        return [$summary];
    }

    /**
     * One project-live-insights request. Null (with lastError set) on any
     * failure; the raw metric-group array otherwise.
     *
     * @param  array<string, int|string>  $query
     * @return array<int, mixed>|null
     */
    protected function requestInsights(array $query): ?array
    {
        $settings = app(ClaritySettings::class);
        $token = (string) $settings->apiToken();
        $baseUrl = rtrim($settings->baseUrl(), '/');

        if ($token === '') {
            $this->lastError = 'Clarity API token missing';

            return null;
        }

        $resp = Http::timeout(45)
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'])
            ->get("{$baseUrl}/project-live-insights", $query);

        if (! $resp->successful()) {
            Log::warning('Clarity API call failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
                'query' => $query,
            ]);
            $this->lastError = 'Clarity API request failed (check token/project id and API permissions).';

            return null;
        }

        $payload = $resp->json();
        if (! is_array($payload)) {
            $this->lastError = 'Unexpected Clarity payload shape.';

            return null;
        }

        return $payload;
    }

    protected function toInt(array $row, array $keys): int
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return (int) $row[$k];
            }
        }

        return 0;
    }

    protected function toFloat(array $row, array $keys): float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return round((float) $row[$k], 4);
            }
        }

        return 0.0;
    }
}
