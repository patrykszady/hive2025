<?php

namespace App\Observers;

use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;

/**
 * The /{locale}/welcome marketing pages are full-page cached
 * (App\Http\Middleware\CachePublicPage, key 'public-page:'.md5(path), 60
 * min) and their "What contractors say" section reads this table directly —
 * a review added, edited, or unpublished from the admin must show (or stop
 * showing) at once, not up to an hour later.
 */
class TestimonialObserver
{
    public function saved(Testimonial $testimonial): void
    {
        $this->forgetWelcomePages();
    }

    public function deleted(Testimonial $testimonial): void
    {
        $this->forgetWelcomePages();
    }

    protected function forgetWelcomePages(): void
    {
        foreach (array_keys(config('locales.supported', ['en' => []])) as $locale) {
            Cache::forget('public-page:'.md5("{$locale}/welcome"));
        }
    }
}
