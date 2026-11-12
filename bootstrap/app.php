<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\StoreEmailTracking;
use SsSystems\Platform\Seo\AiTraffic\TrackAiTraffic;
use SsSystems\Platform\Seo\Bing\Console\BingCoverageCommand;
use SsSystems\Platform\Seo\Bing\Console\BingSubmitSitemapsCommand;
use SsSystems\Platform\Seo\Clarity\Console\ClaritySyncCommand;
use SsSystems\Platform\Seo\Faq\Bank\Console\FaqBankCommand;
use SsSystems\Platform\Seo\IndexNow\Console\IndexNowCommand;
use SsSystems\Platform\Seo\Intel\Console\IntelCommand;
use SsSystems\Platform\Seo\KeywordResearch\Console\KeywordResearchCommand;
use SsSystems\Platform\Seo\MapPack\Console\MapPackCompetitorsCommand;
use SsSystems\Platform\Seo\MapPack\Console\MapPackGridCommand;
use SsSystems\Platform\Seo\PageSpeed\Console\PageSpeedSyncCommand;
use SsSystems\Platform\Seo\Rankings\Console\ShowRankingsCommand;
use SsSystems\Platform\Seo\Rankings\Console\TrackRankingsCommand;
use SsSystems\Platform\Seo\Snapshot\Console\StoreSeoSnapshotCommand;
use SsSystems\Platform\Seo\Visibility\Console\AiMentionsCommand;
use SsSystems\Platform\Seo\Visibility\Console\BacklinkGapCommand;
use SsSystems\Platform\Seo\Visibility\Console\CompetitorBrandTrackCommand;
use SsSystems\Platform\Seo\Visibility\Console\DomainOverviewCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        // Where visitors come from (kit 0.17): traffic:collect, scheduled in
        // routes/console.php. The default app/Console/Commands directory is
        // already registered by Application::configure()'s own withCommands()
        // call above, so this adds only the kit's command class.
        \SsSystems\Platform\Traffic\Console\CollectTrafficCommand::class,
        // The kit's SEO tools (0.19.1, 2026-11-01): the same commands
        // J. Peterson Design/gs.construction run. seo:track-rankings
        // replaced this app's own retired seo:rank-check (deleted
        // 2026-11-01 — see SeoSnapshotController's docblock).
        TrackRankingsCommand::class,
        ShowRankingsCommand::class,
        KeywordResearchCommand::class,
        DomainOverviewCommand::class,
        BacklinkGapCommand::class,
        AiMentionsCommand::class,
        CompetitorBrandTrackCommand::class,
        // Registered even though this SaaS site has no service-area
        // coordinates (HiveSeoProfile::latitude()/longitude() are null) —
        // seo:map-pack-grid refuses cleanly with a clear message instead of
        // being left unavailable, and seo:map-pack-competitors is harmless
        // to register (it just reads an always-empty map_pack_competitors
        // table here).
        MapPackGridCommand::class,
        MapPackCompetitorsCommand::class,
        // Change intel (0.19.2): seo:intel, one DataForSEO family per run —
        // see AppServiceProvider's IntelSettings/IntelRunner bindings.
        IntelCommand::class,
        // Page speed (kit 0.19.3): seo:psi-sync, replacing this app's own
        // retired App\Console\Commands\SeoPsiSync.
        PageSpeedSyncCommand::class,
        // Microsoft Clarity (kit 0.20.0, 2026-11-02): seo:clarity-sync,
        // writing the clarity_daily_metrics rows the clarity-health report
        // reads — see AppServiceProvider's ClarityClient/ClarityMetricsReader
        // bindings, replacing this app's own retired App\Services\
        // MicrosoftClarityService/ClaritySettingsMetricsReader.
        ClaritySyncCommand::class,
        // Bing sitemaps + IndexNow (kit 0.19.4, 2026-11-01): seo:bing-submit-sitemaps
        // reads this site's own robots.txt and submits whatever Bing is
        // missing; seo:indexnow tells Bing/Yandex/Seznam/Naver which pages
        // are new or changed. See AppServiceProvider's BingWebmasterApi/
        // IndexNowKey bindings and routes/web.php's key-file route.
        BingSubmitSitemapsCommand::class,
        IndexNowCommand::class,
        // Bing coverage (kit 0.22.0, 2026-11-10): seo:bing-coverage, what
        // Bing's index holds of the site — see AppServiceProvider's
        // BingCoverageClient/BingCoverageWriter/BingCoverageUrls bindings.
        BingCoverageCommand::class,
        // The FAQ bank (kit 0.24.0, 2026-11-10): seo:faq-bank, the questions
        // and answers behind /geo/answers.json and /llms-full.txt, derived
        // from this site's own marketing pages and blog — see
        // AppServiceProvider's FaqBankSource/TextGenerator/FaqBankStore
        // bindings and routes/console.php's nightly run.
        FaqBankCommand::class,
        // The stored SEO snapshot (kit 0.26.0, 2026-11-11): seo:snapshot-store
        // builds what GET seo/snapshot hands out — see AppServiceProvider's
        // SeoSnapshotSource binding and routes/console.php.
        StoreSeoSnapshotCommand::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            if ($user?->is_browsing_as_client) {
                $client = $user->primary_client;

                return $client
                    ? route('clients.show', $client)
                    : route('clients.index');
            }

            return AppServiceProvider::HOME;
        });

        $middleware->throttleApi();

        // Trust all proxies (e.g. Cloudflare, Tailscale Serve) for proper HTTPS detection
        $middleware->trustProxies(at: '*');

        // Redirect legacy domains to main domain
        $middleware->prepend(\App\Http\Middleware\RedirectLegacyDomains::class);

        // Exclude browser timezone cookies from encryption so PHP can read them
        $middleware->encryptCookies(except: [
            'browser_timezone',
            'browser_date',
        ]);

        $middleware->replaceInGroup('web', \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\VerifyCsrfToken::class);

        // Global, not in the 'web' group: Laravel runs auth and route-model
        // binding ahead of group middleware (middleware priority), so a guest's
        // redirect from /hub to /login, or the 404 of a missing model, left
        // without the noindex header (found 2026-10-25).
        $middleware->append(\App\Http\Middleware\NoIndexSubdomains::class);
        $middleware->append(\App\Http\Middleware\NoIndexNonPublic::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\LoadAuthVendor::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\LogForbiddenResponses::class);

        // AI assistants' referrals and AI crawlers' hits, counted per day
        // into ai_traffic_daily for the SEO Reports "AI Assistant
        // Visibility" card (kit 0.19.1, 2026-11-01). This app never tracked
        // AI traffic its own way, so this is additive, not a replacement.
        $middleware->append(TrackAiTraffic::class);

        $middleware->alias([
            'admin.access' => \App\Http\Middleware\AdminAccess::class,
            'vendor.access' => \App\Http\Middleware\VendorAccessControl::class,
            'vendor.own-redirect' => \App\Http\Middleware\RedirectOwnVendorToDashboard::class,
            'registered' => \App\Http\Middleware\EnsureUserRegistered::class,
            'telnyx.signature' => \App\Http\Middleware\VerifyTelnyxSignature::class,
            // /api/admin/v1's bearer-token guard (ss-systems is the only caller).
            'admin.api.auth' => \SsSystems\Platform\Http\Admin\AuthenticateAdminApi::class,
            // Sanctum's own ability checks, used by /api/app/v1 (the Hive
            // Contractors app): 'abilities' requires every ability listed,
            // 'ability' requires any one of them.
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            // /api/app/v1's team gate — see EnsureAppUserAllowed's docblock.
            'app.access' => \App\Http\Middleware\EnsureAppUserAllowed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Stale Livewire snapshots after deployment — expected, not actionable
        $exceptions->dontReport(\Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException::class);
    })->create();

// Register mail event listeners
Event::listen(MessageSent::class, StoreEmailTracking::class);
