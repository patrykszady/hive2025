<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\StoreEmailTracking;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            if ($user?->is_client_user) {
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

        $middleware->appendToGroup('web', \App\Http\Middleware\NoIndexSubdomains::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\NoIndexNonPublic::class);

        $middleware->alias([
            'vendor.access' => \App\Http\Middleware\VendorAccessControl::class,
            'vendor.own-redirect' => \App\Http\Middleware\RedirectOwnVendorToDashboard::class,
            'registered' => \App\Http\Middleware\EnsureUserRegistered::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// Register mail event listeners
Event::listen(MessageSent::class, StoreEmailTracking::class);
