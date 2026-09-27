<?php

namespace App\Support\Seo\Inspection;

use App\Services\GoogleSearchConsoleService;
use Illuminate\Http\Client\ConnectionException;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector;

/**
 * UrlInspector over this site's own GoogleSearchConsoleService::inspectUrl().
 * Ported from dawnsellshomes' identical adapter. The one thing this adds
 * beyond a pass-through: the kit's UrlInspector contract requires
 * inspect() to never throw for a network failure, and inspectUrl() itself
 * does not catch ConnectionException (same as every other HTTP call on
 * that service) — that try/catch lives here instead.
 */
class SearchConsoleUrlInspector implements UrlInspector
{
    /** @var array{status: ?int, message: string}|null */
    private ?array $connectionError = null;

    public function __construct(private readonly GoogleSearchConsoleService $service) {}

    public function inspect(string $siteUrl, string $url): ?array
    {
        $this->connectionError = null;

        try {
            return $this->service->inspectUrl($siteUrl, $url);
        } catch (ConnectionException $e) {
            $this->connectionError = ['status' => null, 'message' => $e->getMessage()];

            return null;
        }
    }

    public function lastError(): ?array
    {
        return $this->connectionError ?? $this->service->getLastError();
    }

    /**
     * The service-account file is present and a property is set — the same
     * "connected" signal GET platforms/status reports. Whether the account
     * actually has permission on the property is a live-API question the
     * inspect() call itself surfaces through lastError().
     */
    public function isReady(): bool
    {
        return $this->service->isConfigured();
    }

    public function siteUrl(): string
    {
        return $this->service->siteUrl();
    }
}
