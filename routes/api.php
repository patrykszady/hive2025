<?php

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
