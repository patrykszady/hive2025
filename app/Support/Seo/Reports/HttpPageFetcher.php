<?php

namespace App\Support\Seo\Reports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Reports\Contracts\PageFetcher;
use SsSystems\Platform\Reports\PageFetchResult;

/**
 * PageFetcher over Laravel's Http client — ported verbatim from
 * dawnsellshomes' identical class. One GET request, the caller's own
 * timeout/User-Agent (each self-crawl report keeps its own — see
 * PageFetcher's own docblock), a network failure turned into
 * PageFetchResult::failed() instead of a thrown exception.
 */
class HttpPageFetcher implements PageFetcher
{
    public function fetch(string $url, int $timeoutSeconds, string $userAgent): PageFetchResult
    {
        $start = microtime(true);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get($url);
        } catch (ConnectionException) {
            return PageFetchResult::failed($this->elapsedMs($start));
        } catch (\Throwable) {
            return PageFetchResult::failed($this->elapsedMs($start));
        }

        return PageFetchResult::make(
            $response->status(),
            $response->headers(),
            $response->body(),
            $this->elapsedMs($start),
        );
    }

    private function elapsedMs(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
