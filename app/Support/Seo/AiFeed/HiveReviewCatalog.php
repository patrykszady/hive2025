<?php

namespace App\Support\Seo\AiFeed;

use App\Models\Testimonial;
use SsSystems\Platform\Seo\AiFeed\Contracts\ReviewCatalog;

/**
 * This site's `reviews` for /ai-feed.json: the published customer reviews
 * of Hive itself — App\Models\Testimonial, the same rows the home page's
 * "What contractors say" section renders (routes/web.php's `welcome`
 * closure), newest review first, public display name ("First L") only,
 * exactly as the page shows them. A review unpublished in the admin leaves
 * the feed the moment the kit's 15-minute cache turns over.
 */
final class HiveReviewCatalog implements ReviewCatalog
{
    public function reviews(): array
    {
        return Testimonial::published()
            ->orderByDesc('review_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Testimonial $testimonial): array => array_filter([
                'id' => $testimonial->id,
                'author' => $testimonial->display_name,
                'role' => $testimonial->role,
                'rating' => $testimonial->rating,
                'review_date' => $testimonial->review_date?->toDateString(),
                'body' => $testimonial->body,
                'platform' => $testimonial->platform,
                'url' => $testimonial->review_url,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
    }
}
