<?php

use App\Http\Controllers\Api\Admin\V1\BlogPostController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// This site's Blog screen (ss-systems' App\Livewire\Admin\BlogPostList/
// BlogPostForm, App\Services\BlogExtApiClient there) — see
// BlogPostController's docblock. Same route shape as dawnsellshomes.com's
// no-projects Blog screen.
AdminApi::declare('blog');

    Route::get('blog-posts', [BlogPostController::class, 'index'])->name('blog-posts.index');
    Route::post('blog-posts', [BlogPostController::class, 'store'])->name('blog-posts.store');
    Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])->name('blog-posts.show');
    Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])->name('blog-posts.update');
    Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])->name('blog-posts.destroy');
