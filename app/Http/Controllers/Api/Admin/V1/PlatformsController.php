<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\BingSettings;
use App\Support\Seo\SeoCredentialsImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;

/**
 * GET platforms/status — read by both ss-systems' Platforms screen
 * (App\Livewire\Admin\PlatformsSettings there, which renders the
 * `managed: 'server'` Search Console card read-only) and its SEO screen's
 * Connect Services modal (App\Livewire\Admin\SeoConnectServices, which
 * only links back to Platforms for Search Console and drives the Bing
 * fields itself). This app DOES declare the `platforms` ping domain
 * (routes/api-admin/platforms.php) even though Search Console here rides a
 * server-held service account rather than a per-owner Google sign-in —
 * there is a real Platforms screen worth showing (the read-only Search
 * Console status card), unlike a site with no platforms endpoints at all.
 *
 * Key names match the other kit sites' PlatformsController::gscStatus()
 * shape, plus `managed: 'server'` and `property` per ss.systems' contract
 * for a server-managed Search Console card.
 */
class PlatformsController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                // Exactly which Connect Services sections apply to this
                // site — see resources/views/livewire/admin/
                // seo-connect-services.blade.php (ss-systems) for how an
                // absent list (an older site) keeps every section instead.
                'services' => ['gsc', 'bing'],
                'gsc' => $this->gscStatus(),
                'bing' => $this->bingStatus(),
            ],
        ]);
    }

    /**
     * connected/configured: the service-account file exists AND a property
     * is set — see GoogleSearchConsoleService::isConfigured()'s docblock
     * for why that is a cheap, offline check rather than a live probe.
     * managed is always 'server': there is no per-owner OAuth grant here,
     * just a server-held credential.
     */
    protected function gscStatus(): array
    {
        $service = app(GoogleSearchConsoleService::class);
        $configured = $service->isConfigured();
        $summary = SeoSyncRun::summary('search_console');

        $finishedAt = $summary['finished_at'] ?? null;

        return [
            'connected' => $configured,
            'configured' => $configured,
            'managed' => 'server',
            'property' => $service->siteUrl() ?: null,
            'last_synced_at' => $finishedAt,
            'last_sync_status' => $summary['status'] ?? null,
            'last_sync_error' => $summary['error'] ?? null,
            // Twice the schedule's cadence, same definition of "stale" as
            // ss-systems/platform-kit's SearchConsoleSyncRule.
            'sync_stale' => $finishedAt
                ? Carbon::parse($finishedAt)->lt(now()->subHours(SearchConsoleSyncRule::SYNCED_STALE_AFTER_HOURS))
                : null,
        ];
    }

    /**
     * configured: an admin-saved key or BING_WMT_KEY is set. source is
     * 'admin' | 'env' | null (App\Support\Seo\PlatformSettingCredential).
     */
    protected function bingStatus(): array
    {
        $settings = app(BingSettings::class);

        return [
            'configured' => $settings->isConfigured(),
            'source' => $settings->source(),
        ];
    }

    /**
     * POST platforms/bing/credentials {api_key} — a blank re-submit keeps
     * whatever key is already stored (never overwrites with empty), same
     * as the other kit sites' saveBingCredentials(). The response is
     * always the fresh status block, never the raw value.
     */
    public function saveBingCredentials(Request $request): JsonResponse
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:255']]);

        if (! empty($data['api_key'])) {
            PlatformSetting::put(BingSettings::SETTING_API_KEY, $data['api_key']);
        }

        return response()->json(['data' => ['bing' => $this->bingStatus()]]);
    }

    /** DELETE platforms/bing/credentials — back to whatever BING_WMT_KEY provides (usually nothing). */
    public function clearBingCredentials(): JsonResponse
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, null);

        return response()->json(['data' => ['bing' => $this->bingStatus()]]);
    }

    /**
     * POST platforms/seo-credentials/import {sources?: string[]} —
     * ss.systems' Connect Services modal's "Move here": copies BING_WMT_KEY
     * into the encrypted platform_settings row without an ssh session. Only
     * 'bing' is a valid source on this site (App\Support\Seo\
     * SeoCredentialsImport::SOURCES). Never returns a credential value,
     * only presence/absence and the fresh status block.
     */
    public function importSeoCredentialsFromEnv(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(SeoCredentialsImport::SOURCES)],
        ]);

        $result = app(SeoCredentialsImport::class)->run($data['sources'] ?? [], false);

        return response()->json(['data' => [
            'imported' => $result['imported'],
            'already_stored' => $result['already_stored'],
            'absent' => $result['absent'],
            'status' => [
                'bing' => $this->bingStatus(),
            ],
        ]]);
    }
}
