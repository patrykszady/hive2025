<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\StoreEmailTracking;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        // Where visitors come from (kit 0.17): traffic:collect, scheduled in
        // routes/console.php. The default app/Console/Commands directory is
        // already registered by Application::configure()'s own withCommands()
        // call above, so this adds only the kit's command class.
        \SsSystems\Platform\Traffic\Console\CollectTrafficCommand::class,
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
