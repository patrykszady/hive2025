<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Models\Testimonial;
use App\Services\MetaSocialService;
use App\Support\Seo\BingSettings;
use App\Support\Seo\ClaritySettings;
use App\Support\Seo\DataForSeoSettings;
use App\Support\Seo\PsiSettings;
use App\Support\Seo\SeoCredentialsImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use SsSystems\Platform\Auth\OAuthState;
use SsSystems\Platform\Google\BusinessProfile\Client;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use SsSystems\Platform\Google\BusinessProfile\Http\Concerns\ServesGbpPlatform;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use SsSystems\Platform\Seo\Google\ServiceAccountSearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;

/**
 * GET platforms/status — read by both ss-systems' Platforms screen
 * (App\Livewire\Admin\PlatformsSettings there, which renders a card for
 * each key present: google/gbp/gsc/bing/meta) and its SEO screen's Connect
 * Services modal (App\Livewire\Admin\SeoConnectServices, which only reads
 * gsc/bing via the `services` list and links back to Platforms for Search
 * Console).
 *
 * The connections that apply to a software company (2026-09-26): Google
 * sign-in (Business Profile scope only), Google Business Profile (connect,
 * one listing, reviews) and Meta (Facebook Page + Instagram Business).
 * Search Console keeps running on the server-held service account (see
 * gscStatus()'s docblock).
 *
 * Google (kit 0.14.0, "one Google", 2026-09-28): every platforms/gbp/* and
 * platforms/google/* endpoint, the gbp half of the {provider} dispatch and
 * the `google`/`gbp` status blocks come from the kit's ServesGbpPlatform —
 * one implementation for every tenant, signing in through the ONE shared
 * Google OAuth client (services.google.oauth). This class supplies only
 * the two required hooks and hive's own: the imported-review flags and
 * counts over `testimonials` (platform='google', external_id). This app has
 * no project photos, so every media write stays a 405 (the trait's default)
 * with this app's own wording (gbpMediaRefusal()); the media list is a
 * read-only pass-through. POST/DELETE platforms/google/credentials refuse:
 * the client is shared server configuration, never a per-site value.
 *
 * Key names match the other kit sites' PlatformsController shapes exactly
 * (gscStatus() plus `managed: 'server'`/`property`, metaStatus()) so
 * ss-systems' shared Platforms screen renders and behaves identically here
 * — see dawnsellshomes.com's PlatformsController, the closest reference
 * (also single-tenant, also no project photos).
 */
class PlatformsController extends Controller
{
    use ServesGbpPlatform;

    /**
     * Providers this controller drives an OAuth dance for: Google Business
     * Profile ('gbp') and Meta ('meta'). Search Console runs on a
     * server-held service account here, never OAuth (see gscStatus()).
     */
    protected const OAUTH_PROVIDERS = ['gbp', 'meta'];

    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                // Exactly which Connect Services sections apply to this
                // site — see resources/views/livewire/admin/
                // seo-connect-services.blade.php (ss-systems) for how an
                // absent list (an older site) keeps every section instead.
                // 'meta' is intentionally NOT in this list — that modal is
                // SEO-only; Meta lives on the Platforms screen's own card.
                'services' => ['gsc', 'bing', 'clarity', 'pagespeed', 'dataforseo'],
                // Google sign-in (the shared client, Business Profile
                // only) + the Business Profile card itself — both the kit's.
                'google' => $this->googleStatus(),
                'gbp' => $this->gbpStatus(),
                'gsc' => $this->gscStatus(),
                'bing' => $this->bingStatus(),
                'meta' => $this->metaStatus(),
                // The four global SEO-source credentials the Connect
                // Services modal drives — see clarityStatus()/
                // pagespeedStatus()/dataForSeoStatus()'s docblocks.
                'clarity' => $this->clarityStatus(),
                'pagespeed' => $this->pagespeedStatus(),
                'dataforseo' => $this->dataForSeoStatus(),
            ],
        ]);
    }

    /**
     * GET platforms/{provider}/oauth-url — the callback is this app's own
     * /admin-oauth/{provider}/callback (routes/web.php), carrying a signed
     * SsSystems\Platform\Auth\OAuthState value as 'state' so that session-less
     * callback can verify the request — see that route's docblock for why
     * (this app's whole /admin surface is a stateless proxy, so there is
     * no admin session here). 'gbp' is the kit's gbpOauthUrl(): with no
     * shared client on the server it answers `url: null` and a sentence
     * rather than a link to a Google error page.
     */
    public function oauthUrl(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        return match ($provider) {
            'gbp' => $this->gbpOauthUrl(),
            'meta' => response()->json(['data' => ['url' => app(MetaSocialService::class)->getOAuthUrl(
                route(OAuthClient::CALLBACK_ROUTE, ['provider' => 'meta']),
                MetaSocialService::OAUTH_SCOPES,
                OAuthState::make('meta'),
            )]]),
        };
    }

    /** DELETE platforms/{provider} — forgets that provider's stored grant (Google: oauth_tokens; Meta: platform_settings). */
    public function disconnect(string $provider): Response
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        if ($provider === 'gbp') {
            return $this->disconnectGbp();
        }

        app(MetaSocialService::class)->disconnect();

        return response()->noContent();
    }

    /**
     * POST platforms/meta/test-connection — this app has no posting
     * pipeline of any kind (see App\Services\MetaSocialService's
     * docblock), so this short-circuits the same way dawnsellshomes.com's
     * equivalent does rather than pretending to probe the Graph API.
     */
    public function testMetaConnection(): JsonResponse
    {
        $service = app(MetaSocialService::class);

        if (! $service->isConnected()) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => 'Meta is not connected yet.',
            ]]);
        }

        return response()->json(['data' => [
            'ok' => false,
            'message' => 'Meta test connection is not available on this site yet.',
        ]]);
    }

    /**
     * connected: a stored platform_settings grant or env fallback provides
     * a token AND at least one of the Facebook Page/Instagram Business
     * ids — same definition as jpeterson-design's/dawnsellshomes.com's
     * metaStatus().
     */
    protected function metaStatus(): array
    {
        $service = app(MetaSocialService::class);
        $creds = $service->getCredentials();
        $isOauth = $creds['source'] === 'oauth';

        return [
            'enabled' => (bool) config('services.meta.enabled'),
            'connected' => $creds['token'] !== null,
            'source' => $creds['source'],
            'page_id' => $creds['page_id'],
            'page_name' => $creds['page_name'],
            'instagram_id' => $creds['ig_id'],
            'instagram_username' => $creds['ig_username'],
            'instagram_configured' => $service->isInstagramConfigured(),
            'facebook_configured' => $service->isFacebookConfigured(),
            'granted_by' => $isOauth ? $service->grantedByEmail() : null,
            'granted_at' => $isOauth ? $service->grantedAt()?->toIso8601String() : null,
            'updated_at' => $isOauth ? $service->credentialsUpdatedAt()?->toIso8601String() : null,
            'app_credentials_configured' => filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')),
        ];
    }

    /**
     * connected/configured: the service-account file exists AND a property
     * is set — see ServiceAccountSearchConsoleClient::isConfigured()'s
     * docblock (ss-systems/platform-kit) for why that is a cheap, offline
     * check rather than a live probe. managed is always 'server': there is
     * no per-owner OAuth grant here, just a server-held credential.
     */
    protected function gscStatus(): array
    {
        $service = app(ServiceAccountSearchConsoleClient::class);
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
     * configured: BOTH the project id and the API token are set (admin or
     * env) — the tag itself only needs the project id (see
     * App\Support\Seo\ClaritySettings::projectId(), read directly by the
     * guest layout), but the health report also needs the API token, so
     * "configured" here means the whole integration, not just the tag.
     */
    protected function clarityStatus(): array
    {
        $settings = app(ClaritySettings::class);

        return [
            'configured' => $settings->isConfigured(),
            'source' => $settings->source(),
        ];
    }

    /**
     * PageSpeed has no hard connected/disconnected state (it runs keyless
     * too) — 'configured' here means "using your own key", not "working".
     * ss.systems' Connect Services modal reads 'using_own_key' (not
     * 'configured') to decide the PageSpeed card's state.
     */
    protected function pagespeedStatus(): array
    {
        $settings = app(PsiSettings::class);

        return [
            'configured' => $settings->usingOwnKey(),
            'using_own_key' => $settings->usingOwnKey(),
            'source' => $settings->source(),
        ];
    }

    /**
     * DataForSEO is the one source where a stored value means ss.systems
     * provisioned this tenant (see DataForSeoSettings's docblock), so
     * source() reports 'platform' rather than 'admin'.
     */
    protected function dataForSeoStatus(): array
    {
        $settings = app(DataForSeoSettings::class);

        return [
            'configured' => $settings->isConfigured(),
            'source' => $settings->source(),
        ];
    }

    /**
     * The /{locale}/welcome marketing pages are full-page cached
     * (App\Http\Middleware\CachePublicPage, key 'public-page:'.md5(path),
     * 60 min) and the Clarity tag reads ClaritySettings::projectId()
     * straight off the page render — a saved/cleared project id must show
     * (or stop showing) on the very next visit, not up to an hour later.
     * Same pattern as App\Observers\TestimonialObserver::forgetWelcomePages().
     */
    protected function forgetWelcomePages(): void
    {
        foreach (array_keys(config('locales.supported', ['en' => []])) as $locale) {
            Cache::forget('public-page:'.md5("{$locale}/welcome"));
        }
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
     * ss.systems' Connect Services modal's "Move here": copies each of
     * BING_WMT_KEY/CLARITY_PROJECT_ID+CLARITY_API_TOKEN/PAGESPEED_API_KEY/
     * DATAFORSEO_LOGIN+DATAFORSEO_PASSWORD into the encrypted
     * platform_settings row without an ssh session
     * (App\Support\Seo\SeoCredentialsImport::SOURCES). Never returns a
     * credential value, only presence/absence and the fresh status block.
     */
    public function importSeoCredentialsFromEnv(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(SeoCredentialsImport::SOURCES)],
        ]);

        $sources = $data['sources'] ?? [];
        $result = app(SeoCredentialsImport::class)->run($sources, false);

        // Same reasoning as saveClarityCredentials(): an id imported from
        // env must be live on the very next page load, not wait out the
        // welcome pages' hour-long cache.
        if ($sources === [] || in_array('clarity', $sources, true)) {
            $this->forgetWelcomePages();
        }

        return response()->json(['data' => [
            'imported' => $result['imported'],
            'already_stored' => $result['already_stored'],
            'absent' => $result['absent'],
            'status' => [
                'bing' => $this->bingStatus(),
                'clarity' => $this->clarityStatus(),
                'pagespeed' => $this->pagespeedStatus(),
                'dataforseo' => $this->dataForSeoStatus(),
            ],
        ]]);
    }

    /**
     * POST platforms/clarity/credentials {project_id, api_token} — a blank
     * re-submit never overwrites a stored secret, same as
     * saveBingCredentials(). The token is a JWT — Clarity's run to ~700
     * characters (2026-09-23), so a 255 cap would silently refuse a real
     * one.
     */
    public function saveClarityCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:4096'],
        ]);

        if (! empty($data['project_id'])) {
            PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, $data['project_id']);
        }
        if (! empty($data['api_token'])) {
            PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, $data['api_token']);
        }

        // The marketing pages are full-page cached for an hour
        // (App\Http\Middleware\CachePublicPage) and read the project id
        // straight from ClaritySettings on every render — without this the
        // tag would not appear until the cache aged out.
        $this->forgetWelcomePages();

        return response()->json(['data' => ['clarity' => $this->clarityStatus()]]);
    }

    /** DELETE platforms/clarity/credentials */
    public function clearClarityCredentials(): JsonResponse
    {
        PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, null);
        PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, null);
        $this->forgetWelcomePages();

        return response()->json(['data' => ['clarity' => $this->clarityStatus()]]);
    }

    /** POST platforms/pagespeed/credentials {api_key} — optional; PSI runs keyless too. */
    public function savePagespeedCredentials(Request $request): JsonResponse
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:255']]);

        if (! empty($data['api_key'])) {
            PlatformSetting::put(PsiSettings::SETTING_API_KEY, $data['api_key']);
        }

        return response()->json(['data' => ['pagespeed' => $this->pagespeedStatus()]]);
    }

    /** DELETE platforms/pagespeed/credentials — back to Google's shared quota. */
    public function clearPagespeedCredentials(): JsonResponse
    {
        PlatformSetting::put(PsiSettings::SETTING_API_KEY, null);

        return response()->json(['data' => ['pagespeed' => $this->pagespeedStatus()]]);
    }

    /**
     * POST platforms/dataforseo/credentials {login, password} — called by
     * ss.systems alone (same design as jpeterson-design's/gs.construction's
     * identical endpoint): DataForSEO is the platform's own metered
     * account, provisioned per tenant; this site has no owner-facing field
     * for it.
     */
    public function saveDataForSeoCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['login'])) {
            PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, $data['login']);
        }
        if (! empty($data['password'])) {
            PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, $data['password']);
        }

        return response()->json(['data' => ['dataforseo' => $this->dataForSeoStatus()]]);
    }

    /** DELETE platforms/dataforseo/credentials — ss.systems switching this tenant back off. */
    public function clearDataForSeoCredentials(): JsonResponse
    {
        PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, null);
        PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, null);

        return response()->json(['data' => ['dataforseo' => $this->dataForSeoStatus()]]);
    }

    // ---- Google Business Profile: the kit's ServesGbpPlatform hooks -----

    /** The one Business Profile client (AppServiceProvider binds it per resolution). */
    protected function gbpClient(): Client
    {
        return app(Client::class);
    }

    /** This app's single linked listing: platform_settings' gbp.* keys, env ids as the fallback. */
    protected function gbpListingStore(): ListingStore
    {
        return app(ListingStore::class);
    }

    /**
     * Of these Google review ids, the ones already held as a testimonial
     * (platform='google' carrying the id in external_id — this app has no
     * review_urls pivot, unlike jpeterson-design/gsc, see
     * App\Models\Testimonial's docblock), so ss.systems' import creates only
     * the new ones.
     *
     * @param  list<string>  $reviewIds
     * @return list<string>
     */
    protected function gbpImportedReviewIds(array $reviewIds): array
    {
        return Testimonial::query()
            ->where('platform', 'google')
            ->whereIn('external_id', $reviewIds)
            ->pluck('external_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Google reviews held as testimonials (platform='google'), the same two
     * numbers gsc/jpeterson-design report for Houzz/Angi.
     *
     * @return array{count: int, latest: ?string}
     */
    protected function gbpReviewStats(): array
    {
        $googleReviews = Testimonial::query()->where('platform', 'google');
        $latest = (clone $googleReviews)->max('review_date');

        return [
            'count' => $googleReviews->count(),
            'latest' => $latest ? Carbon::parse($latest)->toDateString() : null,
        ];
    }

    /**
     * Every media write answers 405 here (the trait's default for a site
     * that sends no photos — ss-systems' `gbp_photos_managed` stays off for
     * this site), in this app's own words: it has no project photos of any
     * kind.
     */
    protected function gbpMediaRefusal(): string
    {
        return request()->isMethod('DELETE')
            ? 'This site has no project photos to remove from Google.'
            : 'This site has no project photos to send to Google.';
    }

    /**
     * POST platforms/gsc/sync — ss.systems asks this site to pull Search
     * Console now instead of at the nightly run: its Platforms screen's
     * Refresh does, so an owner who has just added our service account to
     * the property sees the answer in a minute (2026-09-29). Queues the kit's
     * RunArtisanCommandDetached and returns at once: a full pull takes
     * minutes, far longer than ss.systems' 15-second client waits. The result
     * shows on platforms/status's gsc block (last_synced_at, last_sync_error).
     */
    public function syncGsc(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) ($request->input('days') ?? SearchConsoleSyncRule::DEFAULT_DAYS)));
        $lag = max(0, min(7, (int) ($request->input('lag_days') ?? SearchConsoleSyncRule::DEFAULT_LAG_DAYS)));

        RunArtisanCommandDetached::dispatch('seo:gsc-sync', ['--days' => $days, '--lag-days' => $lag]);

        return response()->json(['data' => ['queued' => true]]);
    }
}
