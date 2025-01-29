<?php

namespace App\Providers;

use App\Models\Bid;
use App\Models\Client;
use App\Models\EstimateLineItem;
use App\Models\Expense;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\UserVendor;
use App\Models\Vendor;
use App\Observers\BidObserver;
use App\Observers\ClientObserver;
use App\Observers\EstimateLineItemObserver;
use App\Observers\ExpenseObserver;
use App\Observers\LineItemObserver;
use App\Observers\ProjectObserver;
use App\Observers\UserVendorObserver;
use App\Observers\VendorObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&  $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        //     \URL::forceScheme('https');
        // }
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
            return $request->user()
                && in_array($request->user()->email, [
                    'patryk@gs.construction',
                ]);
        });

        // Blade::component('mails.base', \App\View\Components\Base::class);

        $this->bootEvent();
        $this->bootRoute();
    }

    public function bootEvent()
    {
        Bid::observe(BidObserver::class);
        Client::observe(ClientObserver::class);
        Expense::observe(ExpenseObserver::class);
        EstimateLineItem::observe(EstimateLineItemObserver::class);
        LineItem::observe(LineItemObserver::class);
        Project::observe(ProjectObserver::class);
        UserVendor::observe(UserVendorObserver::class);
        Vendor::observe(VendorObserver::class);
    }

    public function bootRoute()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }
}
