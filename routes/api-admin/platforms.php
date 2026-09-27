<?php

use App\Http\Controllers\Api\Admin\V1\PlatformsController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// The Platforms screen: a read-only, server-managed Search Console status
// card (no per-owner Google sign-in here) plus the admin-writable Bing
// Webmaster Tools key the SEO screen's Connect Services modal drives. See
// PlatformsController's docblock.
AdminApi::declare('platforms');

Route::get('platforms/status', [PlatformsController::class, 'status'])->name('platforms.status');
Route::post('platforms/bing/credentials', [PlatformsController::class, 'saveBingCredentials'])->name('platforms.bing.save');
Route::delete('platforms/bing/credentials', [PlatformsController::class, 'clearBingCredentials'])->name('platforms.bing.clear');
Route::post('platforms/seo-credentials/import', [PlatformsController::class, 'importSeoCredentialsFromEnv'])->name('platforms.seo-credentials.import');
