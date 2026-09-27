<?php

namespace App\Services;

use App\Support\Seo\PsiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PageSpeed Insights API wrapper (free, 25k req/day with API key) — ported
 * from jpeterson-design's/gs.construction's identical service, reading its
 * optional key through App\Support\Seo\PsiSettings rather than config()
 * directly. PSI never requires a key (it just runs on Google's shared
 * quota without one), so nothing here gates on the key's presence.
 *
 * https://developers.google.com/speed/docs/insights/v5/get-started
 */
class PageSpeedInsightsService
{
    protected const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Run PSI for a URL. Returns null on failure.
     *
     * @return array{
     *   performance:?int, accessibility:?int, best_practices:?int, seo:?int,
     *   lab_lcp_ms:?int, lab_fcp_ms:?int, lab_tbt_ms:?int, lab_cls:?float, lab_si_ms:?int,
     *   field_lcp_ms:?int, field_inp_ms:?int, field_cls:?float, field_overall:?string,
     * }|null
     */
    public function run(string $url, string $strategy = 'mobile'): ?array
    {
        $key = app(PsiSettings::class)->apiKey();

        $query = 'url='.urlencode($url)
            .'&strategy='.urlencode($strategy)
            .'&category=performance&category=accessibility&category=best-practices&category=seo'
            .($key ? '&key='.urlencode($key) : '');

        try {
            $resp = Http::connectTimeout(15)
                ->timeout(75)
                ->retry(3, 2500, function (\Exception $exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && $exception->response->serverError();
                })
                ->get(self::API.'?'.$query);
        } catch (\Exception $e) {
            Log::warning('PSI: request failed', [
                'url' => $url,
                'strategy' => $strategy,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $resp->successful()) {
            Log::warning('PSI: failed', [
                'url' => $url,
                'strategy' => $strategy,
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);

            return null;
        }

        $body = $resp->json();
        $cats = data_get($body, 'lighthouseResult.categories', []);
        $audits = data_get($body, 'lighthouseResult.audits', []);
        $field = data_get($body, 'loadingExperience.metrics', []);

        return [
            'performance' => $this->pct($cats, 'performance'),
            'accessibility' => $this->pct($cats, 'accessibility'),
            'best_practices' => $this->pct($cats, 'best-practices'),
            'seo' => $this->pct($cats, 'seo'),
            'lab_lcp_ms' => $this->ms($audits, 'largest-contentful-paint'),
            'lab_fcp_ms' => $this->ms($audits, 'first-contentful-paint'),
            'lab_tbt_ms' => $this->ms($audits, 'total-blocking-time'),
            'lab_cls' => $this->num($audits, 'cumulative-layout-shift'),
            'lab_si_ms' => $this->ms($audits, 'speed-index'),
            'field_lcp_ms' => data_get($field, 'LARGEST_CONTENTFUL_PAINT_MS.percentile'),
            'field_inp_ms' => data_get($field, 'INTERACTION_TO_NEXT_PAINT.percentile'),
            'field_cls' => (function () use ($field) {
                $v = data_get($field, 'CUMULATIVE_LAYOUT_SHIFT_SCORE.percentile');

                return $v !== null ? round($v / 100, 3) : null;
            })(),
            'field_overall' => data_get($body, 'loadingExperience.overall_category'),
        ];
    }

    protected function pct(array $cats, string $key): ?int
    {
        $score = data_get($cats, "{$key}.score");

        return $score === null ? null : (int) round($score * 100);
    }

    protected function ms(array $audits, string $key): ?int
    {
        $v = data_get($audits, "{$key}.numericValue");

        return $v === null ? null : (int) round($v);
    }

    protected function num(array $audits, string $key): ?float
    {
        $v = data_get($audits, "{$key}.numericValue");

        return $v === null ? null : round((float) $v, 3);
    }
}
