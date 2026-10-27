<?php

use App\Http\Controllers\Api\App\V1\AuthController as AppAuthController;
use App\Http\Controllers\Api\App\V1\CallLogController as AppCallLogController;
use App\Http\Controllers\Api\App\V1\ClientController as AppClientController;
use App\Http\Controllers\Api\App\V1\PlannerController as AppPlannerController;
use App\Http\Controllers\Api\App\V1\ProjectController as AppProjectController;
use App\Http\Controllers\Api\App\V1\ThreadController as AppThreadController;
use App\Http\Controllers\Api\App\V1\ThreadMessageController as AppThreadMessageController;
use App\Http\Controllers\Api\App\V1\ThreadToolsController as AppThreadToolsController;
use App\Http\Controllers\Api\App\V1\VoiceController as AppVoiceController;
use App\Http\Controllers\Api\App\V1\WebSessionController as AppWebSessionController;
use App\Http\Controllers\Api\BuildingCodesController;
use App\Http\Controllers\Api\LeadsController;
use App\Http\Controllers\Api\MailboxesController;
use App\Http\Controllers\Api\ProjectZipCountsController;
use App\Http\Controllers\Api\ServiceCallEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('v1')
    ->group(function () {
        Route::get('projects/zip-counts', ProjectZipCountsController::class)
            ->name('api.v1.projects.zip-counts');

        Route::get('leads', [LeadsController::class, 'index'])
            ->name('api.v1.leads.index');

        Route::post('leads', [LeadsController::class, 'store'])
            ->name('api.v1.leads.store');

        // The site took a submission back (a supplier's quote it later
        // recognised as mail to us, not an enquiry): the lead it made here
        // goes with it, consults and orphaned client included.
        Route::delete('leads/{lead}', [LeadsController::class, 'destroy'])
            ->name('api.v1.leads.destroy');

        // The vendor's connected mailboxes: what gs.construction reads for
        // email enquiries (see MailboxesController).
        Route::get('mailboxes', MailboxesController::class)
            ->name('api.v1.mailboxes');

        // Asked by the site's mail reader before it files an email as a lead:
        // a client's service request opens a service call here instead.
        Route::post('service-calls/from-email', ServiceCallEmailController::class)
            ->name('api.v1.service-calls.from-email');
        // A served town's adopted building codes, for the site's town pages
        // (BuildingCodesController). An unknown town starts a lookup.
        Route::get('building-codes', BuildingCodesController::class)
            ->middleware('throttle:120,1')
            ->name('api.v1.building-codes');
    });

/*
|--------------------------------------------------------------------------
| Hive Contractors app API — /api/app/v1
|--------------------------------------------------------------------------
|
| The iPhone app (GS Construction's own team only — primary_vendor_id 1).
| Email one-time-code sign-in, then a Sanctum personal access token scoped
| to the "app" ability; every authenticated route also runs through
| app.access (EnsureAppUserAllowed), which re-checks primary_vendor_id so a
| token survives only as long as its owner stays on the team.
*/
Route::prefix('app/v1')->name('api.app.v1.')->group(function () {
    Route::post('login/code', [AppAuthController::class, 'sendCode'])
        ->middleware('throttle:app-login-code')
        ->name('login.code');

    Route::post('login', [AppAuthController::class, 'login'])
        ->middleware('throttle:app-login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'abilities:app', 'app.access'])->group(function () {
        Route::get('me', [AppAuthController::class, 'me'])->name('me');
        Route::post('logout', [AppAuthController::class, 'logout'])->name('logout');

        Route::get('threads', [AppThreadController::class, 'index'])->name('threads.index');
        Route::post('threads', [AppThreadController::class, 'store'])->name('threads.store');
        Route::get('threads/{thread}', [AppThreadController::class, 'show'])->whereNumber('thread')->name('threads.show');

        Route::get('threads/{thread}/messages', [AppThreadMessageController::class, 'index'])->name('threads.messages.index');
        Route::post('threads/{thread}/messages', [AppThreadMessageController::class, 'store'])->name('threads.messages.store');
        Route::post('threads/{thread}/read', [AppThreadMessageController::class, 'markRead'])->name('threads.read');

        // The web conversation's composer tools.
        Route::get('threads/{thread}/schedule', [AppThreadToolsController::class, 'schedulePreview'])->whereNumber('thread')->name('threads.schedule.preview');
        Route::post('threads/{thread}/schedule', [AppThreadToolsController::class, 'sendSchedule'])->whereNumber('thread')->name('threads.schedule.send');
        Route::post('threads/{thread}/consult-draft', [AppThreadToolsController::class, 'consultDraft'])->whereNumber('thread')->name('threads.consult-draft');

        Route::get('voice/token', [AppVoiceController::class, 'token'])->name('voice.token');
        Route::post('calls', [AppVoiceController::class, 'store'])->middleware('throttle:30,1')->name('calls.store');

        // The Calls tab: the web calls dashboard's list, detail and blocking.
        Route::get('calls/ringing', [AppVoiceController::class, 'ringing'])->name('calls.ringing');
        Route::get('calls', [AppCallLogController::class, 'index'])->name('calls.index');
        Route::post('calls/unblock', [AppCallLogController::class, 'unblock'])->name('calls.unblock');
        Route::get('calls/{call}', [AppCallLogController::class, 'show'])->whereNumber('call')->name('calls.show');
        Route::post('calls/{call}/block', [AppCallLogController::class, 'block'])->whereNumber('call')->name('calls.block');

        // The Projects tab — same cards, filters, search and status change
        // as the web Projects index/page. 'statuses' is registered ahead of
        // the numeric {project} routes for clarity, though whereNumber()
        // alone already keeps it from being swallowed as an id.
        Route::get('projects/statuses', [AppProjectController::class, 'statuses'])->name('projects.statuses');
        Route::get('projects', [AppProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{project}', [AppProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
        Route::post('projects/{project}/status', [AppProjectController::class, 'updateStatus'])->whereNumber('project')->name('projects.status.update');

        // The Planner tab — the same week agenda, "To schedule" tray and
        // action-items-from-calls-and-texts list as the web planner
        // (App\Livewire\Planner\CardsIndex), via AppPlannerAgenda.
        Route::get('planner', [AppPlannerController::class, 'index'])->name('planner.index');
        Route::get('action-items', [AppPlannerController::class, 'actionItems'])->name('action-items.index');
        Route::post('action-items/{item}/done', [AppPlannerController::class, 'markDone'])->whereNumber('item')->name('action-items.done');
        Route::post('action-items/{item}/dismiss', [AppPlannerController::class, 'dismiss'])->whereNumber('item')->name('action-items.dismiss');

        // The Clients tab — same list, search and detail as the web
        // Clients index/show pages.
        Route::get('clients', [AppClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [AppClientController::class, 'show'])->whereNumber('client')->name('clients.show');
        Route::get('clients/{client}/thread', [AppClientController::class, 'thread'])->whereNumber('client')->name('clients.thread');

        // The "open full page" hand-off into a signed-in web session — see
        // WebSessionController and routes/web.php's web-session.consume.
        Route::post('web-session', [AppWebSessionController::class, 'store'])->name('web-session.store');

        // Every further section of the app's API (expenses, payments,
        // vendors, …) lives in its own file under routes/api-app/, loaded
        // here so it inherits this group's prefix, name and middleware.
        foreach (glob(base_path('routes/api-app/*.php')) ?: [] as $appSectionRoutes) {
            require $appSectionRoutes;
        }
    });
});

/*
|--------------------------------------------------------------------------
| Management API — /api/admin/v1
|--------------------------------------------------------------------------
|
| The ONLY caller is ss-systems, talking to this app over HTTP with a
| bearer token (admin.api.auth — App\Http\Middleware\AuthenticateAdminApi).
| Stateless: no Sanctum, no session, just the token. Hive is single-tenant
| from ss-systems' point of view (one connected site, key 'hive'), so
| unlike gsc there is no admin.api.tenant pin here.
|
| A separate prefixed group from the 'v1' one above (different guard,
| different caller) — see AdminProxyController's docblock for why /admin/*
| itself is a raw byte relay rather than anything routed here.
|
| 6000/min: the real gate is the bearer token (one trusted server-to-server
| caller), whose admin screens fan out many requests per page — matches
| gsc's/dawnsellshomes' throttle on this same route group.
*/
Route::prefix('admin/v1')->name('api.admin.v1.')->middleware(['throttle:6000,1', 'admin.api.auth'])->group(function () {
    // One file per domain (routes/api-admin/*.php), each declaring the
    // capabilities it serves through SsSystems\Platform\Http\Admin\
    // CapabilityRegistry — the same registry gsc, jpeterson-design and
    // ss-systems itself declare through, so a screen's API is one
    // self-contained file and two screens never edit the same one.
    foreach (glob(__DIR__.'/api-admin/*.php') as $file) {
        require $file;
    }
});
