<?php

use App\Http\Controllers\Api\Admin\V1\SocialMediaController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// The Social Media screen: this site's own profile roster. See
// SocialMediaController's docblock for why no automation/posting routes
// are declared here.
AdminApi::declare('social-media');

Route::get('social-media', [SocialMediaController::class, 'index'])->name('social-media.index');
Route::put('social-media/urls', [SocialMediaController::class, 'saveUrls'])->name('social-media.urls');
