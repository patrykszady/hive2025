<?php

namespace App\Providers;

use App\Models\Bid;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\EstimateLineItem;
use App\Models\Expense;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\Vendor;
use App\Models\VendorDoc;

use App\Observers\BidObserver;
use App\Observers\CallLogObserver;
use App\Observers\ClientObserver;
use App\Observers\EstimateLineItemObserver;
use App\Observers\ExpenseObserver;
use App\Observers\LineItemObserver;
use App\Observers\ProjectObserver;
use App\Observers\VendorDocObserver;
use App\Observers\VendorObserver;

use App\Mail\Transport\NylasTransport;
use App\Services\NylasService;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

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

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
            // Allow bearer token authentication for remote hosts
            if ($request->bearerToken() === config('log-viewer.hosts.production.auth.token', env('LOG_VIEWER_PRODUCTION_TOKEN'))) {
                return true;
            }

            // Allow specific users via web authentication
            return $request->user()
                && in_array($request->user()->email, [
                    'patryk@gs.construction',
                ]);
        });

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
            }
        }

        $this->bootEvent();
        $this->bootRoute();

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
        EstimateLineItem::observe(EstimateLineItemObserver::class);
        LineItem::observe(LineItemObserver::class);
        Project::observe(ProjectObserver::class);
        Vendor::observe(VendorObserver::class);
        VendorDoc::observe(VendorDocObserver::class);
    }

    public function bootRoute()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }
}
