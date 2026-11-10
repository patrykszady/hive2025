<?php

namespace App\Support\Seo\Faq;

use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;

/**
 * hive.contractors' /geo/answers.json list: the fixed config/geo-answers.php
 * answers alone, the same shape as gs.construction's own ConfigFaqCatalog —
 * no per-service/per-area FAQ merge (jpeterson-design's addition), since
 * this site's product pages carry no FAQ sections of their own. Bound in
 * AppServiceProvider.
 */
final class ConfigFaqCatalog implements FaqCatalog
{
    public function items(): array
    {
        return (array) config('geo-answers.answers', []);
    }

    public function publisherExtras(): array
    {
        return [
            'knowsLanguage' => (array) config('geo-answers.meta.languages', ['English']),
        ];
    }
}
