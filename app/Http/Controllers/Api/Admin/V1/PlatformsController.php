<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Models\Testimonial;
use App\Services\GoogleBusinessProfileService;
use App\Services\GoogleSearchConsoleService;
use App\Services\MetaSocialService;
use App\Support\GoogleBusinessListing;
use App\Support\GoogleOAuthApp;
use SsSystems\Platform\Auth\OAuthState;
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
 * sign-in (this app's own OAuth client, Business Profile scope only),
 * Google Business Profile (connect, one listing, reviews) and Meta
 * (Facebook Page + Instagram Business). This app has no projects/listings/
 * photos of its own, so unlike jpeterson-design/gsc there is no photo
 * upload pipeline anywhere in this controller — see gbpListMedia()'s
 * siblings below, which refuse outright rather than port dead code. Search
 * Console keeps running on the server-held service account (see
 * gscStatus()'s docblock) and is untouched by this change.
 *
 * Key names match the other kit sites' PlatformsController shapes exactly
 * (gscStatus() plus `managed: 'server'`/`property`, gbpStatus(),
 * metaStatus()) so ss-systems' shared Platforms screen renders and behaves
 * identically here — see dawnsellshomes.com's PlatformsController, the
 * closest reference (also single-tenant, also no project photos).
 */
class PlatformsController extends Controller
{
    /**
     * Providers this controller drives an OAuth dance for: Google Business
     * Profile ('gbp') and Meta ('meta'). Search Console runs on a
     * server-held service account here, never OAuth (see gscStatus()).
     */
    protected const OAUTH_PROVIDERS = ['gbp', 'meta'];

    /** Google's review star enum, as the central admin stores a rating. */
    protected const GBP_STAR_RATINGS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

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
                // Google sign-in (Business Profile only) + the Business
                // Profile card itself.
                'google' => GoogleOAuthApp::status(),
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
     * no admin session here).
     */
    public function oauthUrl(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        $redirectUri = route('admin-oauth.callback', ['provider' => $provider]);
        $state = OAuthState::make($provider);

        $url = match ($provider) {
            'gbp' => app(GoogleBusinessProfileService::class)->getOAuthUrl($redirectUri, $state),
            'meta' => app(MetaSocialService::class)->getOAuthUrl($redirectUri, $state),
        };

        return response()->json(['data' => ['url' => $url]]);
    }

    /** DELETE platforms/{provider} — forgets that provider's stored grant (Google: oauth_tokens; Meta: platform_settings). */
    public function disconnect(string $provider): Response
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        match ($provider) {
            'gbp' => app(GoogleBusinessProfileService::class)->disconnect(),
            'meta' => app(MetaSocialService::class)->disconnect(),
        };

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

    // ---- Google sign-in (Business Profile OAuth client) ---------------

    /**
     * POST platforms/google/credentials {client_id, client_secret} — this
     * app's own Google OAuth client, stored encrypted. Mirrors
     * dawnsellshomes.com's/jpeterson-design's saveGoogleCredentials() shape
     * — ss.systems' Platforms screen only ever sends the two values (see
     * App\Support\GoogleOAuthApp's docblock). The secret is never returned.
     */
    public function saveGoogleCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        GoogleOAuthApp::save($data['client_id'], $data['client_secret']);

        return response()->json(['data' => [
            'google' => GoogleOAuthApp::status(),
            'gbp' => $this->gbpStatus(),
        ]]);
    }

    /** DELETE platforms/google/credentials — back to whatever the server's env provides (usually nothing). */
    public function clearGoogleCredentials(): JsonResponse
    {
        GoogleOAuthApp::clear();

        return response()->json(['data' => [
            'google' => GoogleOAuthApp::status(),
            'gbp' => $this->gbpStatus(),
        ]]);
    }

    /**
     * GET platforms/gbp/listings — the Business Profile accounts and
     * listings this authorisation can see, so the admin can offer them
     * instead of asking for ids nobody has. Verbatim port of
     * dawnsellshomes.com's gbpListings() (this app is single-tenant too, so
     * there is no "belongs to another site" filtering to do).
     */
    public function gbpListings(): JsonResponse
    {
        $service = app(GoogleBusinessProfileService::class);

        if (! $service->hasRefreshToken()) {
            return response()->json(['message' => 'Connect Google Business Profile first.'], 422);
        }

        if (! $service->hasBusinessScope()) {
            return response()->json([
                'message' => 'This authorisation only covers signing in. Reconnect and allow Business Profile access.',
                'data' => ['business_scope_granted' => false, 'accounts' => []],
            ], 422);
        }

        $accounts = [];

        foreach ($service->listAccounts() as $account) {
            $accountId = GoogleBusinessListing::bareId((string) ($account['name'] ?? ''));

            if ($accountId === '') {
                continue;
            }

            $accounts[] = [
                'account_id' => $accountId,
                'name' => $account['accountName'] ?? $account['name'] ?? $accountId,
                'type' => $account['type'] ?? null,
                'locations' => array_map(fn (array $location) => [
                    'location_id' => GoogleBusinessListing::bareId((string) ($location['name'] ?? '')),
                    'title' => $location['title'] ?? null,
                    'website' => $location['websiteUri'] ?? null,
                    'maps_url' => $location['metadata']['mapsUri'] ?? null,
                    'place_id' => $location['metadata']['placeId'] ?? null,
                    'address' => implode(', ', array_filter([
                        implode(' ', (array) ($location['storefrontAddress']['addressLines'] ?? [])),
                        $location['storefrontAddress']['locality'] ?? null,
                        $location['storefrontAddress']['administrativeArea'] ?? null,
                    ])) ?: null,
                ], $service->listLocations($accountId)),
            ];
        }

        if ($accounts === [] && $service->getLastError()) {
            return response()->json([
                'message' => 'Google refused the listing lookup: '.($service->getLastError()['message'] ?? 'unknown error'),
            ], 422);
        }

        return response()->json(['data' => [
            'business_scope_granted' => true,
            'accounts' => $accounts,
            'selected' => [
                'account_id' => config(GoogleBusinessListing::CONFIG_PATH.'.account_id'),
                'location_id' => config(GoogleBusinessListing::CONFIG_PATH.'.location_id'),
            ],
        ]]);
    }

    /** POST platforms/gbp/listing — which listing this app's grant reads reviews from. */
    public function saveGbpListing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:191'],
            'location_id' => ['required', 'string', 'max:191'],
        ]);

        GoogleBusinessListing::link($data['account_id'], $data['location_id']);
        GoogleBusinessListing::apply();

        return response()->json(['data' => $this->gbpStatus()]);
    }

    /**
     * GET platforms/gbp/reviews?account_id=&location_id=[&page_token=]
     *
     * One listing's Google reviews for the central admin, which imports
     * them as testimonials. Each review says whether this app already
     * holds it (a `testimonials` row with platform='google' carrying its
     * external_id — this app has no review_urls pivot, unlike
     * jpeterson-design/gsc, see App\Models\Testimonial's docblock), so the
     * admin creates only the new ones. A pass-through to Google with this
     * app's grant; the import itself lives in ss.systems.
     */
    public function gbpReviews(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:191'],
            'location_id' => ['required', 'string', 'max:191'],
            'page_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $service = app(GoogleBusinessProfileService::class);

        if (! $service->hasRefreshToken()) {
            return response()->json(['message' => 'Connect Google Business Profile first.'], 422);
        }

        $page = $service->fetchReviewsFor(
            GoogleBusinessListing::bareId($data['account_id']),
            GoogleBusinessListing::bareId($data['location_id']),
            $data['page_token'] ?? null,
        );

        if ($page === null) {
            $message = 'Google refused the review lookup: '.($service->getLastError()['message'] ?? 'unknown error');

            return response()->json(['message' => $message, 'errors' => ['google' => [$message]]], 422);
        }

        $reviews = collect($page['reviews'])
            ->map(fn (array $r) => ['id' => GoogleBusinessListing::bareId((string) ($r['name'] ?? '')), 'raw' => $r])
            ->filter(fn (array $r) => $r['id'] !== '')
            ->values();

        $held = Testimonial::query()
            ->where('platform', 'google')
            ->whereIn('external_id', $reviews->pluck('id')->all())
            ->pluck('external_id')
            ->all();

        return response()->json(['data' => [
            'reviews' => $reviews->map(fn (array $r) => [
                'id' => $r['id'],
                'reviewer' => $r['raw']['reviewer']['displayName'] ?? 'Google Reviewer',
                'rating' => self::GBP_STAR_RATINGS[$r['raw']['starRating'] ?? ''] ?? null,
                'comment' => (string) ($r['raw']['comment'] ?? ''),
                'created_at' => $r['raw']['createTime'] ?? null,
                'url' => 'https://www.google.com/maps/reviews?reviewid='.$r['id'],
                'imported' => in_array($r['id'], $held, true),
            ])->all(),
            'next_page_token' => $page['nextPageToken'],
            'total_review_count' => $page['totalReviewCount'],
            'average_rating' => $page['averageRating'],
        ]]);
    }

    /**
     * GET platforms/gbp/media?account_id=&location_id= — a read-only
     * pass-through to Google's media list, for parity with the other kit
     * sites' Platforms screens. This app has no project photos of any
     * kind, so uploadGbpMedia()/deleteGbpMedia()/saveGbpMediaLedger() below
     * refuse outright instead of porting a pipeline nothing here would
     * ever drive (see ss-systems' `gbp_photos_managed`, which stays off
     * for this site).
     */
    public function gbpListMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:191'],
            'location_id' => ['required', 'string', 'max:191'],
        ]);

        $service = app(GoogleBusinessProfileService::class);

        if (! $service->hasRefreshToken()) {
            return response()->json(['message' => 'Connect Google Business Profile first.'], 422);
        }

        $items = $service->listMediaFor(
            GoogleBusinessListing::bareId($data['account_id']),
            GoogleBusinessListing::bareId($data['location_id']),
        );

        if ($items === null) {
            $message = 'Google refused the media lookup: '.($service->getLastError()['message'] ?? 'unknown error');

            return response()->json(['message' => $message, 'errors' => ['google' => [$message]]], 422);
        }

        return response()->json(['data' => ['items' => $items, 'count' => count($items)]]);
    }

    /** POST platforms/gbp/media — refused: this site has no project photos to send to Google. */
    public function uploadGbpMedia(): JsonResponse
    {
        return response()->json(['message' => 'This site has no project photos to send to Google.'], 405);
    }

    /** DELETE platforms/gbp/media — refused: this site has no project photos to remove from Google. */
    public function deleteGbpMedia(): JsonResponse
    {
        return response()->json(['message' => 'This site has no project photos to remove from Google.'], 405);
    }

    /** PUT platforms/gbp/media/ledger — refused: there is no upload ledger to reconcile on this site. */
    public function saveGbpMediaLedger(): JsonResponse
    {
        return response()->json(['message' => 'This site has no project photos to send to Google.'], 405);
    }

    /**
     * The gbp status block — see this class's docblock and ss.systems'
     * PlatformsSettings render for the exact fields the Platforms screen
     * reads. Same shape as jpeterson-design's/dawnsellshomes.com's
     * gbpStatus(), minus jpeterson's 'markets' key — this app has no
     * markets/areas concept (see App\Support\GoogleBusinessListing's
     * docblock), so there is no per-market routing to report.
     */
    protected function gbpStatus(): array
    {
        $service = app(GoogleBusinessProfileService::class);
        $token = $service->getStoredToken();
        $config = config('services.google.business_profile');

        return [
            'connected' => $service->hasRefreshToken(),
            'source' => $token?->refresh_token ? 'oauth' : ($service->hasRefreshToken() ? 'env' : null),
            'email' => $token?->granted_by_email,
            'granted_at' => $token?->created_at?->toIso8601String(),
            'updated_at' => $token?->updated_at?->toIso8601String(),
            'access_token_expires_at' => $token?->access_token_expires_at?->toIso8601String(),
            'scopes' => $token?->scopes,
            'app_credentials_configured' => ! empty($config['client_id']) && ! empty($config['client_secret']),
            'fully_configured' => $service->isConfigured(),
            'client_id_configured' => ! empty($config['client_id']),
            'client_secret_configured' => ! empty($config['client_secret']),
            'account_id_configured' => ! empty($config['account_id']),
            'location_id_configured' => ! empty($config['location_id']),
            'refresh_token_present' => $service->hasRefreshToken(),
            // A connection can exist and still be useless: Google's consent
            // screen lets the user approve sign-in while declining Business
            // Profile, which yields a token that can name the user and do
            // nothing else.
            'business_scope_granted' => $service->hasBusinessScope(),
            'listing_source' => PlatformSetting::get(GoogleBusinessListing::SETTING_LOCATION_ID) ? 'admin' : (! empty($config['location_id']) ? 'env' : null),
            // Google reviews held as testimonials (platform='google'), the
            // same two numbers gsc/jpeterson-design report for Houzz/Angi.
            'reviews_count' => ($googleReviews = Testimonial::query()->where('platform', 'google'))->count(),
            'latest_review_date' => ($latestGoogle = (clone $googleReviews)->max('review_date')) ? Carbon::parse($latestGoogle)->toDateString() : null,
        ];
    }
}
