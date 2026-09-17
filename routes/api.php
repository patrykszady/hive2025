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
