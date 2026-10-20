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

Route::get('emails/{email}', [AppEmailController::class, 'show'])
    ->whereNumber('email')
    ->name('emails.show');
