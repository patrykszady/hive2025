<?php

use App\Http\Controllers\Api\App\V1\EmailController as AppEmailController;
use App\Http\Controllers\Api\App\V1\ProjectCommunicationsController as AppProjectCommunicationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Communications — a project's merged call/text/email timeline, and one
| stakeholder email's full detail.
|--------------------------------------------------------------------------
|
| See App\Services\Communications\ProjectCommunicationsTimeline (shared
| with the web project page) and App\Http\Resources\Api\App\EmailResource.
*/
Route::get('projects/{project}/communications', [AppProjectCommunicationsController::class, 'index'])
    ->whereNumber('project')
    ->name('projects.communications.index');

// The unplaced inbox (Admins): emails the AI could not put on a project.
// Declared before emails/{email} only for readability — {email} is numeric.
Route::get('emails/unplaced', [AppEmailController::class, 'unplaced'])->name('emails.unplaced');
Route::get('emails/{email}', [AppEmailController::class, 'show'])
    ->whereNumber('email')
    ->name('emails.show');
Route::post('emails/{email}/place', [AppEmailController::class, 'place'])->whereNumber('email')->name('emails.place');
Route::post('emails/{email}/dismiss', [AppEmailController::class, 'dismiss'])->whereNumber('email')->name('emails.dismiss');
