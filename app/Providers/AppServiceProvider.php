<?php

namespace App\Providers;

use App\Models\Bid;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\EstimateLineItem;
use App\Models\EstimateSignature;
use App\Models\Expense;
use App\Models\JsErrorState;
use App\Models\Lead;
use App\Models\LineItem;
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
use App\Services\GoogleSearchConsoleService;
use App\Services\NylasService;
use App\Support\GoogleBusinessListing;
use App\Support\GoogleOAuthApp;
use App\Support\Seo\BingSettings;
use App\Support\Seo\BingWriter;
use App\Support\Seo\Inspection\EloquentCoverageStore;
use App\Support\Seo\Inspection\MarketingSitemapSource;
use App\Support\Seo\Inspection\NoTrackedPaths;
use App\Support\Seo\Inspection\SearchConsoleUrlInspector;
use App\Support\Seo\Reports\ClaritySettingsMetricsReader;
use App\Support\Seo\Reports\ConfigSiteIdentity;
use App\Support\Seo\Reports\EloquentHealthDataReader;
use App\Support\Seo\Reports\EloquentPsiSnapshotReader;
use App\Support\Seo\Reports\EloquentQueryMetricsReader;
use App\Support\Seo\Reports\EmptyAreaCatalog;
use App\Support\Seo\Reports\HttpPageFetcher;
use App\Support\Seo\Reports\MarketingSiteCatalog;
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
use SsSystems\Platform\Citations\Contracts\CitationSession;
use SsSystems\Platform\Citations\UnavailableSession;
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
use SsSystems\Platform\Reports\Contracts\SiteCatalog;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;
use SsSystems\Platform\Seo\Bing\BingWriter as KitBingWriter;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore;
use SsSystems\Platform\Seo\Inspection\Contracts\SitemapSource;
use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector;
use SsSystems\Platform\Seo\Inspection\UrlInspectionQuota;
use SsSystems\Platform\Seo\SearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;
use SsSystems\Platform\Seo\SearchConsoleWriter as KitSearchConsoleWriter;

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

        // ss-systems/platform-kit's Search Console contracts. GoogleSearch
        // ConsoleService is authenticated with the GSC_CREDENTIALS service
        // account (App\Support\Google\ServiceAccountToken), not an OAuth
        // grant — this app has no /admin/{site}/platforms Google sign-in
        // screen for Search Console (see PingController).
        $this->app->bind(SearchConsoleClient::class, GoogleSearchConsoleService::class);
        $this->app->bind(SearchConsoleSyncClient::class, GoogleSearchConsoleService::class);
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

        $this->app->bind(CacheInterface::class, fn ($app) => $app->make('cache')->store());

        // The kit's URL Inspection sweep (SsSystems\Platform\Seo\Inspection\
        // UrlInspectionSweep) — see App\Console\Commands\SeoGscInspectBulk
        // and each adapter's own docblock for what it mirrors.
        $this->app->bind(UrlInspector::class, SearchConsoleUrlInspector::class);
        $this->app->bind(SitemapSource::class, MarketingSitemapSource::class);
        $this->app->bind(CoverageStore::class, EloquentCoverageStore::class);
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This app's own Google OAuth client (Business Profile sign-in)
        // and its single linked listing — see App\Support\GoogleOAuthApp
        // and App\Support\GoogleBusinessListing's docblocks. Applied at
        // boot so every request's config() reads reflect what was saved
        // from the central admin's Platforms screen, the same pattern
        // BingSettings::apiKey() reads through PlatformSettingCredential.
        GoogleOAuthApp::apply();
        GoogleBusinessListing::apply();

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
