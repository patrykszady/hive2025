<?php

use App\Http\Controllers\Api\App\V1\AuthController as AppAuthController;
use App\Http\Controllers\Api\App\V1\ThreadController as AppThreadController;
use App\Http\Controllers\Api\App\V1\ThreadMessageController as AppThreadMessageController;
use App\Http\Controllers\Api\App\V1\VoiceController as AppVoiceController;
use App\Http\Controllers\Api\LeadsController;
use App\Http\Controllers\Api\MailboxesController;
use App\Http\Controllers\Api\ProjectZipCountsController;
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

        Route::get('voice/token', [AppVoiceController::class, 'token'])->name('voice.token');
        Route::post('calls', [AppVoiceController::class, 'store'])->middleware('throttle:30,1')->name('calls.store');
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
