<?php

use App\Http\Controllers\Api\Admin\V1\ServiceController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// ss-systems' Services screen (App\Livewire\Admin\ServiceList/ServiceForm)
// — hive's 9 top-level marketing feature areas. See
// App\Http\Controllers\Api\Admin\V1\ServiceController's docblock for why
// store()/destroy() refuse and why there's no reorder/generate route.
AdminApi::declare('services');

Route::get('services', [ServiceController::class, 'index'])->name('services.index');
Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
Route::post('services', [ServiceController::class, 'store'])->name('services.store');
Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
