<?php

namespace App\Services;

use App\Support\Google\ServiceAccountToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;

/**
 * This site's Search Console client — authenticated with the GSC_CREDENTIALS
 * service account, not an OAuth grant: hive.contractors has no
 * /admin/{site}/platforms Google sign-in screen of its own for Search
 * Console (see PingController — Search Console is server-managed here,
 * same as dawnsellshomes). The service account must be added as a user on
 * the Search Console property itself (Full permission, so URL Inspection
 * works too) — see ServiceAccountToken's docblock for the token exchange.
 *
 * Implements the kit's SearchConsoleSyncClient (ss-systems/platform-kit)
 * so SsSystems\Platform\Seo\SearchConsoleSync::run() — the one shared sync
 * implementation — can drive this site's grant, and the three-method
 * SearchConsoleClient interface SitemapStatus reads. querySearchAnalytics()
 * has that interface's exact shape; siteUrl() is a plain config read
 * (single-tenant, same as the other kit sites).
 */
class GoogleSearchConsoleService implements SearchConsoleSyncClient
{
    protected const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';

    protected const SCOPE = 'https://www.googleapis.com/auth/webmasters';

    /** @var array{status: ?int, message: string}|null */
    protected ?array $lastError = null;

    /**
     * The service-account file exists and a property is set — a cheap,
     * offline check (no network call), matching what GET platforms/status
     * reports as gsc.connected/gsc.configured. Whether the service account
     * actually has permission on that property is a live-API question,
     * answered by the sync/inspection calls themselves with a clear
     * message via getLastError() — never a stack trace.
     */
    public function isConfigured(): bool
    {
        return $this->token()->isConfigured() && $this->siteUrl() !== '';
    }

    /**
     * SearchConsoleClient's DISTINCT isReady() — whether a call could reach
     * Google right now. This site has no separate OAuth grant to check the
     * way gs.construction's/jpeterson-design's isReady() does (a stored
     * refresh token) — a service account has no such intermediate state, so
     * isConfigured() itself already answers the same question: the
     * credential file is present and a property is set.
     */
    public function isReady(): bool
    {
        return $this->isConfigured();
    }

    public function siteUrl(): string
    {
        return (string) config('services.google.search_console_property');
    }

    /**
     * @param  array<int, string>  $dimensions
     * @return array<int, array<string, mixed>>|null
     */
    public function querySearchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions,
        int $rowLimit,
        int $startRow,
    ): ?array {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        $url = self::API_BASE.'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query';
        $resp = Http::withToken($token)->timeout(60)->post($url, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
            'startRow' => $startRow,
            'dataState' => 'final',
        ]);

        if (! $resp->successful()) {
            $this->lastError = $this->describeFailure($resp->status(), (string) $resp->body(), $siteUrl);
            Log::warning('GSC: searchAnalytics query failed', [
                'site' => $siteUrl,
                'status' => $resp->status(),
                'body' => mb_substr((string) $resp->body(), 0, 300),
            ]);

            return null;
        }

        return $resp->json('rows', []);
    }

    /**
     * Every sitemap Search Console knows for the property.
     *
     * @return list<array<string, mixed>>|null
     */
    public function listSitemaps(string $siteUrl): ?array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        $resp = Http::withToken($token)->timeout(20)->get(self::API_BASE.'/sites/'.rawurlencode($siteUrl).'/sitemaps');

        if (! $resp->successful()) {
            $this->lastError = $this->describeFailure($resp->status(), (string) $resp->body(), $siteUrl);

            return null;
        }

        return $resp->json('sitemap', []);
    }

    /**
     * Ask Google to (re)fetch a sitemap. Idempotent: submitting an
     * already-registered sitemap just schedules a re-fetch.
     *
     * send('PUT') and NOT ->put(): put() attaches an empty JSON array as
     * the body and Google rejects it with 400 "Root element must be a
     * message". sitemaps.submit requires a bodiless PUT (returns 204).
     * This site has never wired direct sitemap submission before (there is
     * no seo:gsc-submit-sitemaps command here yet) — added now because the
     * kit's SearchConsoleClient contract requires it of every implementor.
     */
    public function submitSitemap(string $siteUrl, string $sitemapUrl): bool
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return false;
        }

        $resp = Http::withToken($token)->timeout(20)->send(
            'PUT',
            self::API_BASE.'/sites/'.rawurlencode($siteUrl).'/sitemaps/'.rawurlencode($sitemapUrl)
        );

        if (! $resp->successful()) {
            $this->lastError = $this->describeFailure($resp->status(), (string) $resp->body(), $siteUrl);

            return false;
        }

        $this->lastError = null;

        return true;
    }

    /**
     * SearchConsoleClient's isAuthStandingCondition() hook. This client has
     * no separate OAuth grant to lapse — every failure describeFailure()
     * above produces (401/403: add the service account as a user on the
     * property; 404: the property isn't verified on this account yet) is
     * already an owner-facing setup instruction, exactly the "someone has
     * to go fix something outside this run" case SsSystems\Platform\Seo\
     * SitemapSubmitter stops on. Anything else (a 5xx, a malformed
     * response) counts against the run like any ordinary failure.
     */
    public function isAuthStandingCondition(?array $error): bool
    {
        return in_array($error['status'] ?? null, [401, 403, 404], true);
    }

    /**
     * One URL through the URL Inspection API — the call the nightly sweep
     * makes per sitemap URL, through App\Support\Seo\Inspection\
     * SearchConsoleUrlInspector. A ConnectionException from Http::post() is
     * deliberately NOT caught here — that adapter is what guards the kit's
     * UrlInspector contract against a network failure reaching the sweep.
     *
     * @return array<string, mixed>|null
     */
    public function inspectUrl(string $siteUrl, string $url): ?array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        $resp = Http::withToken($token)->timeout(60)->post(
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            ['inspectionUrl' => $url, 'siteUrl' => $siteUrl]
        );

        if (! $resp->successful()) {
            $this->lastError = $this->describeFailure($resp->status(), (string) $resp->body(), $siteUrl);

            return null;
        }

        $this->lastError = null;

        return $resp->json('inspectionResult', []);
    }

    /** @return array{status: ?int, message: string}|null */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    protected function token(): ServiceAccountToken
    {
        return ServiceAccountToken::forScope(self::SCOPE);
    }

    /**
     * A valid access token, or null with lastError() set to an
     * owner-facing reason (never a raw exception message from deep inside
     * the JWT/HTTP plumbing).
     */
    protected function getAccessToken(): ?string
    {
        $token = $this->token();
        $access = $token->get();

        if ($access === null) {
            $this->lastError = ['status' => null, 'message' => $token->lastError() ?? 'Could not obtain a Search Console access token.'];
        }

        return $access;
    }

    /**
     * Turn a Google error into something the owner can act on without
     * needing to know what a service account or an OAuth scope is.
     *
     * @return array{status: ?int, message: string}
     */
    protected function describeFailure(int $status, string $body, ?string $siteUrl = null): array
    {
        if ($status === 403 && str_contains($body, 'has not been used in project')) {
            preg_match('/project (\d+)/', $body, $m);
            $project = $m[1] ?? null;

            return [
                'status' => $status,
                'message' => 'The Search Console API is switched off in the Google Cloud project behind this service account'
                    .($project ? ' ('.$project.')' : '')
                    .'. Enable it'
                    .($project ? ' at https://console.developers.google.com/apis/api/searchconsole.googleapis.com/overview?project='.$project : '')
                    .', then retry.',
            ];
        }

        return [
            'status' => $status,
            'message' => match ($status) {
                401, 403 => 'Not authorized'.($siteUrl ? ' for '.$siteUrl : '')
                    .' — the service account (GSC_CREDENTIALS) must be added as a user on that Search Console property with Full permission (URL Inspection needs it, not just Restricted).',
                404 => ($siteUrl ?? 'That property').' is not a property on this Search Console account. Add and verify it first.',
                default => mb_substr($body, 0, 300),
            },
        ];
    }
}
