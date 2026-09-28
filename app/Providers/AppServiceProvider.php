<?php

namespace App\Providers;

use App\Models\Bid;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\EstimateLineItem;
use App\Models\EstimateSignature;
use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
use App\Models\Expense;
use App\Models\JsErrorState;
use App\Models\Lead;
use App\Models\LineItem;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;

use App\Observers\BidObserver;
use App\Observers\CallLogObserver;
use App\Observers\ClientObserver;
use App\Observers\EstimateLineItemObserver;
use App\Observers\EstimateSignatureObserver;
use App\Observers\ExpenseObserver;
use App\Observers\LeadObserver;
use App\Observers\LineItemObserver;
use App\Observers\ProjectObserver;
use App\Observers\VendorDocObserver;
use App\Observers\VendorObserver;

use App\Mail\Transport\NylasTransport;
use App\Models\OAuthToken;
use App\Services\NylasService;
use App\Support\Seo\BingSettings;
use App\Support\Seo\BingWriter;
use App\Support\Seo\Inspection\MarketingSitemapSource;
use App\Support\Seo\Reports\ClaritySettingsMetricsReader;
use App\Support\Seo\Reports\ConfigSiteIdentity;
use App\Support\Seo\Reports\EloquentHealthDataReader;
use App\Support\Seo\Reports\EloquentPsiSnapshotReader;
use App\Support\Seo\Reports\EloquentQueryMetricsReader;
use App\Support\Seo\Reports\EmptyAreaCatalog;
use App\Support\Seo\Reports\HttpPageFetcher;
use App\Support\Seo\Reports\MarketingSiteCatalog;
use App\Support\Seo\Reports\ReportPathStorage;
use App\Support\Seo\SearchConsoleWriter;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Psr\SimpleCache\CacheInterface;
use App\Models\Citation;
use App\Support\Citations\SiteKnownListingsSource;
use SsSystems\Platform\Citations\CitationsAdminActions;
use SsSystems\Platform\Citations\Contracts\CitationSession;
use SsSystems\Platform\Citations\KnownListingsReconciler;
use SsSystems\Platform\Citations\UnavailableBatchRunner;
use SsSystems\Platform\Citations\UnavailableSession;
use SsSystems\Platform\Citations\UnavailableVerificationInbox;
use SsSystems\Platform\Google\Adapters\EloquentTokenStore;
use SsSystems\Platform\Google\BusinessProfile\Adapters\PlatformSettingListingStore;
use SsSystems\Platform\Google\BusinessProfile\Client as GbpClient;
use SsSystems\Platform\Google\BusinessProfile\Contracts\ListingStore;
use SsSystems\Platform\Google\Contracts\TokenStore;
use SsSystems\Platform\Google\OAuthClient;
use SsSystems\Platform\Pulse\BeaconController;
use SsSystems\Platform\Pulse\Contracts\PulseStorage;
use SsSystems\Platform\Pulse\JsErrorGroups;
use SsSystems\Platform\Pulse\Recorder;
use SsSystems\Platform\Pulse\SnapshotBuilder;
use SsSystems\Platform\Pulse\Storage\DatabaseTableStorage;
use SsSystems\Platform\Reports\Contracts\AreaCatalog;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;
use SsSystems\Platform\Reports\Contracts\HealthDataReader;
use SsSystems\Platform\Reports\Contracts\PageFetcher;
use SsSystems\Platform\Reports\Contracts\PsiSnapshotReader;
use SsSystems\Platform\Reports\Contracts\QueryMetricsReader;
use SsSystems\Platform\Reports\Contracts\ReportStorage;
use SsSystems\Platform\Reports\Contracts\SiteCatalog;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter as KitBingWriter;
use SsSystems\Platform\Seo\Google\ServiceAccountSearchConsoleClient;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource;
use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector;
use SsSystems\Platform\Seo\Inspection\EloquentCoverageStore;
use SsSystems\Platform\Seo\Inspection\NoTrackedPaths;
use SsSystems\Platform\Seo\Inspection\UrlInspectionQuota;
use SsSystems\Platform\Seo\SearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\SearchConsoleWriter as KitSearchConsoleWriter;
use SsSystems\Platform\Social\Adapters\PlatformSettingCredentialStore;
use SsSystems\Platform\Social\Contracts\MetaCredentialStore;

use Laravel\Scout\Builder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/hub';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ss-systems/platform-kit's Pulse — first-party usage telemetry
        // behind the central admin's "Site Pulse" card (see docs/PULSE.md
        // in the kit and App\Http\Controllers\Api\Admin\V1\
        // SeoSnapshotController::pulseSnapshot()). This app is single-tenant,
        // so DatabaseTableStorage gets no tenant column/resolver — both the
        // Recorder and the SnapshotBuilder must scope the same way (none),
        // or the Recorder would write rows the SnapshotBuilder's reads never
        // see. The marketing site has no search feature, so SnapshotBuilder's
        // `search_event` stays null (its default) — searches/searched_cities/
        // filters are correctly omitted from the pulse payload rather than
        // sent as zero. `signup` is a click on a marketing page's sign-up
        // call to action (links to route('registration')), fired by the
        // delegated listener in components/layouts/guest.blade.php.
        $this->app->singleton(Recorder::class, fn () => new Recorder(
            storage: new DatabaseTableStorage(DB::connection()),
            events: ['page', 'call', 'email', 'jserr', 'signup'],
        ));
        $this->app->singleton(SnapshotBuilder::class, fn () => new SnapshotBuilder(
            storage: new DatabaseTableStorage(DB::connection()),
            events: ['page', 'call', 'email', 'jserr', 'signup'],
            options: [
                'timezone' => 'America/Chicago',
                'feature_labels' => ['signup' => 'Sign-up clicks'],
            ],
        ));
        $this->app->bind(BeaconController::class, fn ($app) => new BeaconController(
            $app->make(Recorder::class),
            (string) config('app.key'),
        ));
        // The JS Errors board (Api\Admin\V1\JsErrorController) reads jserr
        // events through the same PulseStorage seam as Recorder/
        // SnapshotBuilder above, rather than a hardcoded DB::table() scan —
        // see SsSystems\Platform\Pulse\JsErrorGroups' own docblock.
        $this->app->singleton(PulseStorage::class, fn () => new DatabaseTableStorage(DB::connection()));
        $this->app->singleton(JsErrorGroups::class, fn ($app) => new JsErrorGroups(
            $app->make(PulseStorage::class),
            JsErrorState::class,
        ));

        // ss-systems/platform-kit's Search Console contracts, service-
        // account flavor (kit 0.12.0, gsc-kit-client unit): this app has no
        // /admin/{site}/platforms Google sign-in screen of its own for
        // Search Console (see PingController) — the property is read
        // through the GSC_CREDENTIALS service account instead of a
        // per-owner OAuth grant. This app's own former App\Support\Google\
        // ServiceAccountToken and App\Services\GoogleSearchConsoleService
        // (byte-identical logic, ported from dawnsellshomes' originals) are
        // gone: ONE singleton of the kit's Seo\Google\
        // ServiceAccountSearchConsoleClient satisfies SearchConsoleClient,
        // SearchConsoleSyncClient AND Inspection\Contracts\UrlInspector at
        // once (see the kit's docs/SEARCH-CONSOLE-SERVICE-ACCOUNT.md) — the
        // class needs no separate inspection adapter, so the UrlInspector
        // bind that used to wrap App\Support\Seo\Inspection\
        // SearchConsoleUrlInspector, then the kit's own generic adapter of
        // the same name, lives here too (see below, where CoverageStore/
        // SitemapSource/TrackedPaths are bound).
        $this->app->singleton(ServiceAccountSearchConsoleClient::class, fn ($app) => new ServiceAccountSearchConsoleClient(
            property: (string) config('services.google.search_console_property'),
            credential: config('services.google.search_console_credentials'),
            cache: $app->make(CacheInterface::class),
            http: $app->make(HttpFactory::class),
        ));
        foreach ([SearchConsoleClient::class, SearchConsoleSyncClient::class, UrlInspector::class] as $contract) {
            $this->app->bind($contract, fn ($app) => $app->make(ServiceAccountSearchConsoleClient::class));
        }
        $this->app->bind(KitSearchConsoleWriter::class, SearchConsoleWriter::class);

        // Bing Webmaster Tools: the kit's sync, this app's writer, and a
        // client built from the admin-writable/env-fallback BING_WMT_KEY
        // (App\Support\Seo\BingSettings).
        $this->app->bind(KitBingWriter::class, BingWriter::class);
        $this->app->bind(BingWebmasterClient::class, function ($app) {
            $settings = $app->make(BingSettings::class);

            return new BingWebmasterApi($settings->apiKey(), $settings->siteUrl(), $app->make(HttpFactory::class));
        });
        // The citation builder's remote-browser session (kit 0.11.0, ported
        // verbatim from this file's own former App\Services\Citations\
        // CitationSessionService — see the kit's Citations\
        // UnavailableSession docblock). This host has no Xvfb/Chromium/
        // x11vnc pipeline at all, unlike gsc's/jpeterson's RemoteBrowserSession.
        $this->app->bind(CitationSession::class, UnavailableSession::class);
        // The Citations admin API's own service (kit 0.13.0, ported from
        // gsc's/jpeterson's former Api/Admin/V1/CitationsController — see
        // vendor/ss-systems/platform-kit's Citations\CitationsAdminActions
        // docblock). This host has no batch runner or verification inbox
        // at all, so both null objects are bound instead of the real kit
        // classes gsc/jpeterson use; $dispatchBatch is supplied but never
        // called (batch() always refuses before reaching it, since
        // UnavailableBatchRunner::isAvailable() is always false) — the
        // throw is a deliberate "this should be unreachable" guard, not a
        // real code path (this host has no batch Job class to dispatch).
        // NOT a singleton, on purpose — every other citations binding
        // above is `bind`, not `singleton`.
        $this->app->bind(CitationsAdminActions::class, fn ($app) => new CitationsAdminActions(
            $app->make(CitationSession::class),
            new UnavailableBatchRunner($app->make(CitationSession::class)),
            new UnavailableVerificationInbox,
            new KnownListingsReconciler(new SiteKnownListingsSource, fn () => Citation::query(), 'Social Media'),
            fn () => Citation::query(),
            fn () => ['provider' => 'citations'],
            fn (array $slugs) => throw new \LogicException('This host has no citations batch Job to dispatch.'),
        ));

        // Meta (Facebook Page + Instagram Business) credential storage (kit
        // 0.13.0): this app's own individual encrypted platform_settings
        // rows, one per field, same as before the port (see
        // PlatformSettingCredentialStore's docblock — this app's
        // oauth_tokens table exists only for Google Business Profile). Not
        // a singleton: each resolution re-reads services.meta.* fresh, same
        // as the pre-port class did on every call.
        $this->app->bind(MetaCredentialStore::class, fn () => new PlatformSettingCredentialStore(
            settingModel: PlatformSetting::class,
            prefix: 'meta',
            envFallback: [
                'token' => trim((string) config('services.meta.page_access_token', '')) ?: null,
                'page_id' => trim((string) config('services.meta.facebook_page_id', '')) ?: null,
                'ig_id' => trim((string) config('services.meta.instagram_account_id', '')) ?: null,
                'page_name' => null,
                'ig_username' => null,
            ],
        ));

        $this->app->bind(CacheInterface::class, fn ($app) => $app->make('cache')->store());

        // Google — ONE sign-in client and ONE Business Profile client for
        // every tenant (kit 0.14.0, "one Google", the kit's docs/GOOGLE.md).
        // The client is server configuration (services.google.oauth, the
        // shared GOOGLE_OAUTH_* values), never a per-site row: this app's
        // former App\Support\GoogleOAuthApp overlay, App\Support\
        // GoogleBusinessListing and App\Services\GoogleBusinessProfileService
        // are gone. The grant stays in this app's own oauth_tokens row
        // (OAuthToken, which now also records the issuing client in
        // metadata.oauth_client_id) and the chosen listing in its own gbp.*
        // platform_settings keys, with the env ids only as a fallback. No
        // 'gbp' log channel is defined here, so the client logs to the
        // default one, as the old service did. `bind`, not `singleton`, as
        // the kit asks of every site.
        $this->app->bind(OAuthClient::class, fn ($app) => OAuthClient::fromConfig(
            (array) config('services.google'),
            $app->make(HttpFactory::class),
        ));
        $this->app->bind(TokenStore::class, fn () => new EloquentTokenStore(OAuthToken::class));
        $this->app->bind(ListingStore::class, fn () => new PlatformSettingListingStore(
            PlatformSetting::class,
            config('services.google.business_profile.account_id'),
            config('services.google.business_profile.location_id'),
            config('services.google.business_profile.place_id'),
        ));
        $this->app->bind(GbpClient::class, fn ($app) => new GbpClient(
            $app->make(OAuthClient::class),
            $app->make(TokenStore::class),
            $app->make(CacheInterface::class),
            $app->make(HttpFactory::class),
            Log::channel(config('logging.channels.gbp') ? 'gbp' : null),
            config('services.google.business_profile.refresh_token'),
        ));

        // The kit's URL Inspection sweep (SsSystems\Platform\Seo\Inspection\
        // UrlInspectionSweep) — see App\Console\Commands\SeoGscInspectBulk.
        // UrlInspector is bound above, straight to the service-account
        // client. CoverageStore/TrackedPaths bind to the kit's own
        // Inspection\{EloquentCoverageStore,NoTrackedPaths}
        // (kit/search-console-contract) instead of this site's former local
        // copies, which were nothing but thin wrappers over the same
        // GscCoverageState/GscRichResultIssue models the kit class takes as
        // constructor arguments. This site has no gsc_coverage_state_history
        // table, so EloquentCoverageStore gets only the two required model
        // class-strings. SitemapSource stays this site's own
        // MarketingSitemapSource — a per-site sitemap source, same as every
        // other adopter.
        $this->app->bind(SitemapSource::class, MarketingSitemapSource::class);
        $this->app->bind(CoverageStore::class, fn () => new EloquentCoverageStore(GscCoverageState::class, GscRichResultIssue::class));
        $this->app->bind(TrackedPaths::class, NoTrackedPaths::class);

        // Same daily/per-minute ceiling and Pacific reset as the other kit
        // sites' UrlInspectionQuota binding — one counter (keyed
        // 'gsc.url-inspection') shared across the nightly sweep and any
        // future admin inspect-this-URL button.
        $this->app->singleton(UrlInspectionQuota::class, fn ($app) => new UrlInspectionQuota(
            $app->make(CacheInterface::class),
            'gsc.url-inspection',
            2000,
            600,
        ));

        // The shared SEO report library (ss-systems/platform-kit's
        // SsSystems\Platform\Reports namespace) — see App\Support\Seo\
        // Reports\ReportCapabilities for which of these are actually
        // PROVIDED on this site. AreaCatalog is bound to EmptyAreaCatalog
        // (not provided — see that class's docblock) so HealthReport/
        // AreaPagesAuditReport can still be resolved directly.
        // ClarityMetricsReader/PsiSnapshotReader are bound the same way
        // (always, so `php artisan seo:clarity-health`/`seo:cwv-template`
        // resolve directly) but only listed in ReportCapabilities::
        // provided() once their credential is saved — see that class's
        // docblock.
        $this->app->bind(SiteCatalog::class, MarketingSiteCatalog::class);
        $this->app->bind(SiteIdentity::class, ConfigSiteIdentity::class);
        $this->app->bind(QueryMetricsReader::class, EloquentQueryMetricsReader::class);
        $this->app->bind(HealthDataReader::class, EloquentHealthDataReader::class);
        $this->app->bind(PageFetcher::class, HttpPageFetcher::class);
        $this->app->bind(AreaCatalog::class, EmptyAreaCatalog::class);
        $this->app->bind(ClarityMetricsReader::class, ClaritySettingsMetricsReader::class);
        $this->app->bind(PsiSnapshotReader::class, EloquentPsiSnapshotReader::class);

        // KitReportCommand::maybeSaveMarkdown() (App\Console\Commands\Seo*'s
        // shared base, ss-platform-kit 0.12.0) resolves this to decide where
        // a report's markdown lands — App\Support\Seo\Reports\
        // ReportPathStorage just forwards to the existing SeoStorage::path().
        $this->app->bind(ReportStorage::class, ReportPathStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dev guardrail: a relation accessed without being eager-loaded throws
        // here instead of quietly becoming an N+1 in production. Logs rather
        // than throws so an unlucky path can't break local work outright —
        // watch storage/logs for "lazy loading" entries.
        Model::preventLazyLoading(! app()->isProduction());

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::warning('N+1 risk: lazy-loaded relation', [
                'model' => $model::class,
                'relation' => $relation,
            ]);
        });

        // Guard against Blade compiler state leaking across view compilations.
        // If the internal @forelse counter becomes negative, Blade will generate invalid
        // variables like "$__empty_-1" which causes a syntax error when rendering views.
        Blade::precompiler(function (string $value): string {
            $compiler = app('blade.compiler');

            (function (): void {
                $this->forElseCounter = 0;
            })->call($compiler);

            return $value;
        });

        if (! app()->runningInConsole() && request()->isSecure()) {
            URL::forceScheme('https');
        }

        // When accessed via the Cloudflare tunnel, the browser cannot reach the
        // local Vite dev server. Point Vite to a non-existent hot file so it
        // falls back to the compiled manifest in public/build instead.
        if (! app()->runningInConsole()
            && request()->getHost() === 'dev.hive.contractors') {
            Vite::useHotFile(storage_path('app/.hot-disabled'));
        }
        /**
         * Paginate a standard Laravel Collection.
         *
         * @param  int  $perPage
         * @param  int  $total
         * @param  int  $page
         * @param  string  $pageName
         * @return array
         */
        //FROM https://gist.github.com/simonhamp/549e8821946e2c40a617c85d2cf5af5e#file-collection-php
        Collection::macro('paginate', function ($perPage, $total = null, $page = null, $pageName = 'page') {
            $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

            return new LengthAwarePaginator(
                $this->forPage($page, $perPage),
                $total ?: $this->count(),
                $perPage,
                $page,
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]
            );
        });

        LogViewer::auth(function ($request) {
            // Allow bearer token authentication for remote hosts. A blank
            // configured token must never match a blank (missing) bearer
            // token — `null === null` used to let every unauthenticated
            // request in whenever LOG_VIEWER_PRODUCTION_TOKEN was unset.
            $configuredToken = (string) config('log-viewer.hosts.production.auth.token', env('LOG_VIEWER_PRODUCTION_TOKEN'));
            $bearerToken = (string) $request->bearerToken();

            if ($configuredToken !== '' && $bearerToken !== '' && hash_equals($configuredToken, $bearerToken)) {
                return true;
            }

            // The central admin (ss.systems) reads these logs with its own
            // token (config log-viewer.hub_token), never the production one
            // above — same blank-never-matches rule.
            $hubToken = trim((string) config('log-viewer.hub_token', ''));

            if ($hubToken !== '' && $bearerToken !== '' && hash_equals($hubToken, $bearerToken)) {
                return true;
            }

            // Allow specific users via web authentication
            return $request->user()
                && in_array($request->user()->email, [
                    'patryk@gs.construction',
                ]);
        });

        // The only platform-superadmin check in the app today (see
        // AgentsIndex::render()). Used to gate shared, cross-tenant
        // resources — job-trigger endpoints, the single Menards browser
        // session — that no individual vendor's Admin should control.
        Gate::define('platform-admin', fn (User $user): bool => $user->id === 1);

        // The shared Menards browser is signed into one company's Menards
        // account, so it belongs to that company's admins (not to every
        // tenant's Admin, which the old viewAny-Bank check allowed).
        Gate::define('menards-browser', fn (User $user): bool => ! $user->is_browsing_as_client
            && (int) $user->primary_vendor_id === (int) config('services.menards.owner_vendor_id')
            && $user->can('viewAny', \App\Models\Bank::class));

        // Blade::component('mails.base', \App\View\Components\Base::class);

        // Register Nylas mail transport
        Mail::extend('nylas', function (array $config = []) {
            $nylasService = app(NylasService::class);
            $grantId = $config['grant_id'] ?? config('nylas.default_grant_id');

            return new NylasTransport($nylasService, $grantId);
        });

        // Intercept all emails in local/dev/test environments only.
        // Using "non-production" here is too broad and can accidentally redirect real mail
        // if APP_ENV is misconfigured on a server.
        if (app()->environment('local', 'development', 'testing')) {
            $devEmail = (string) config('mail.dev_email');

            if ($devEmail !== '') {
                Mail::alwaysTo($devEmail);

                // alwaysTo() binds only the DEFAULT mailer instance —
                // Mail::mailer('nylas') would still deliver to real people.
                // MailManager falls back to config('mail.to') when resolving
                // every mailer, so setting it here makes the redirect hold no
                // matter which mailer a job picks.
                config(['mail.to' => ['address' => $devEmail, 'name' => 'Dev Inbox']]);
            } elseif (! app()->environment('testing')) {
                // Fail CLOSED: without a dev inbox configured, route to an
                // unroutable address rather than letting dev mail reach real
                // vendors/clients. (Tests use the array mailer — no risk.)
                Mail::alwaysTo('dev-mail-blackhole@invalid.localhost');
                config(['mail.to' => ['address' => 'dev-mail-blackhole@invalid.localhost', 'name' => null]]);
                \Illuminate\Support\Facades\Log::warning('MAIL_DEV_EMAIL is not set — dev mail is being blackholed. Set it to receive dev email.');
            }
        }

        $this->bootEvent();
        $this->bootRoute();

        // Marketing routes carry a required {locale} prefix, so route('welcome')
        // needs a 'locale' default everywhere — including pages outside the
        // marketing group (login, emails, dashboard). SetLocale overrides this
        // with the active locale on the marketing pages themselves.
        URL::defaults(['locale' => config('locales.default', 'en')]);

        // Set Carbon timezone to match app timezone
        Carbon::setLocale(config('app.locale'));

        // Set default timezone for Carbon
        date_default_timezone_set(config('app.timezone'));

        // Also set Carbon's timezone
        Carbon::setTestNow(null);

        // Extend Scout's Builder to automatically include search attributes
        Builder::macro('paginateWithSearchData', function (int $perPage = null, string $pageName = 'page', int $page = null) {
            $results = $this->paginate($perPage, $pageName, $page);
            $rawResults = $this->raw();

            if (isset($rawResults['hits'])) {
                $searchData = collect($rawResults['hits'])->keyBy('id');

                $results->through(function ($model) use ($searchData) {
                    if ($searchData->has($model->id)) {
                        foreach ($searchData[$model->id] as $key => $value) {
                            $model->setAttribute($key, $value);
                        }
                    }
                    return $model;
                });
            }

            return $results;
        });
    }

    public function bootEvent()
    {
        Bid::observe(BidObserver::class);
        CallLog::observe(CallLogObserver::class);
        Client::observe(ClientObserver::class);
        Expense::observe(ExpenseObserver::class);
        Lead::observe(LeadObserver::class);
        EstimateLineItem::observe(EstimateLineItemObserver::class);
        EstimateSignature::observe(EstimateSignatureObserver::class);
        LineItem::observe(LineItemObserver::class);
        Project::observe(ProjectObserver::class);
        Vendor::observe(VendorObserver::class);
        VendorDoc::observe(VendorDocObserver::class);

        $this->bootSuppressedRecipients();
        $this->bootReplyToSender();
    }

    /**
     * Mail sent FROM the company inbox (crew@… — the vendor's business email)
     * on behalf of a person gets that person as the first Reply-To, so a
     * client who hits Reply reaches whoever wrote to them. The company inbox
     * stays as a second Reply-To: it is the mailbox Hive ingests, and that is
     * how the reply still lands in the CRM.
     *
     * Leaves alone: mail with no known sender (scheduled jobs), mail sent from
     * the person's own address, and mail carrying an explicit Reply-To that is
     * neither the company inbox nor the person.
     */
    protected function bootReplyToSender(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $actor = \App\Support\MailActor::current();
            $actorEmail = strtolower(trim((string) ($actor?->email ?? '')));
            $inbox = strtolower(trim((string) ($actor?->vendor?->business_email ?? '')));

            if ($actorEmail === '' || $inbox === '' || $actorEmail === $inbox) {
                return;
            }

            $message = $event->message;
            $lower = static fn (array $addresses): array => array_map(
                static fn ($address): string => strtolower($address->getAddress()),
                $addresses
            );

            $from = $lower($message->getFrom());
            $replyTo = $lower($message->getReplyTo());

            // Only mail that presents itself as the company inbox.
            if (! in_array($inbox, $from, true) && ! in_array($inbox, $replyTo, true)) {
                return;
            }

            // Respect an explicit Reply-To pointing somewhere else entirely.
            if (array_diff($replyTo, [$inbox, $actorEmail]) !== []) {
                return;
            }

            if (in_array($actorEmail, $replyTo, true)) {
                return;
            }

            $message->replyTo(
                new \Symfony\Component\Mime\Address($actor->email, trim((string) $actor->full_name)),
                new \Symfony\Component\Mime\Address($actor->vendor->business_email, trim((string) $actor->vendor->business_name)),
            );
        });

        // A worker runs many jobs: the sender named by one must not outlive it.
        \Illuminate\Support\Facades\Queue::after(fn () => \App\Support\MailActor::forget());
        \Illuminate\Support\Facades\Queue::failing(fn () => \App\Support\MailActor::forget());
    }

    /**
     * Strip globally suppressed addresses from every outgoing message. If no
     * recipients remain after filtering, the send is cancelled entirely.
     */
    protected function bootSuppressedRecipients(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            $suppressed = config('mail.suppressed_recipients', []);

            if (empty($suppressed)) {
                return null;
            }

            $message = $event->message;

            $filter = static fn (array $addresses): array => array_values(array_filter(
                $addresses,
                static fn ($address): bool => ! in_array(strtolower($address->getAddress()), $suppressed, true)
            ));

            $to = $filter($message->getTo());
            $cc = $filter($message->getCc());
            $bcc = $filter($message->getBcc());

            $message->to(...$to);
            $message->cc(...$cc);
            $message->bcc(...$bcc);

            if (empty($to) && empty($cc) && empty($bcc)) {
                return false;
            }

            return null;
        });
    }


    public function bootRoute()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }
}
