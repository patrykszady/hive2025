<?php

use App\Http\Controllers\Api\App\V1\LienWaiverDrawController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lien Waivers — the GCSS sworn statement / draw package flow
|--------------------------------------------------------------------------
|
| The basic single-waiver list/create/send/cancel/delete routes live in
| sales.php alongside Leads; this file is just the draw package flow
| (App\Livewire\LienWaivers\Index's openSwornStatement()/editDraw()/
| generateSwornStatement()/deleteSwornStatement()), which is big enough to
| warrant its own file. Mirrors that component exactly via
| App\Services\LienWaivers\DrawPackageService — see LienWaiverDrawController.
*/

// 'new' is registered ahead of the numeric {swornStatement} routes for
// clarity, though whereNumber() alone already keeps it from being
// swallowed as an id.
Route::get('lien-waivers/draws', [LienWaiverDrawController::class, 'index'])->name('lien-waivers.draws.index');
Route::get('lien-waivers/draws/new', [LienWaiverDrawController::class, 'new'])->name('lien-waivers.draws.new');
Route::post('lien-waivers/draws', [LienWaiverDrawController::class, 'store'])->name('lien-waivers.draws.store');
Route::get('lien-waivers/draws/{swornStatement}', [LienWaiverDrawController::class, 'show'])->whereNumber('swornStatement')->name('lien-waivers.draws.show');
Route::get('lien-waivers/draws/{swornStatement}/edit', [LienWaiverDrawController::class, 'edit'])->whereNumber('swornStatement')->name('lien-waivers.draws.edit');
Route::put('lien-waivers/draws/{swornStatement}', [LienWaiverDrawController::class, 'update'])->whereNumber('swornStatement')->name('lien-waivers.draws.update');
Route::delete('lien-waivers/draws/{swornStatement}', [LienWaiverDrawController::class, 'destroy'])->whereNumber('swornStatement')->name('lien-waivers.draws.destroy');
