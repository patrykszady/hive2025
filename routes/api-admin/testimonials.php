<?php

use App\Http\Controllers\Api\Admin\V1\TestimonialController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// The Reviews screen: customer quotes about Hive itself, editable here and
// shown (published only) on the marketing home page's "What contractors
// say" section.
AdminApi::declare('testimonials');

// filters before {testimonial} so it's never read as an id.
Route::get('testimonials/filters', [TestimonialController::class, 'filters'])->name('testimonials.filters');
Route::get('testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
Route::post('testimonials', [TestimonialController::class, 'store'])->name('testimonials.store');
Route::get('testimonials/{testimonial}', [TestimonialController::class, 'show'])->name('testimonials.show');
Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->name('testimonials.update');
Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->name('testimonials.destroy');
