<?php

namespace App\Support\Seo\Faq;

use SsSystems\Platform\Seo\Faq\Bank\FaqBankCatalog;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;

/**
 * hive.contractors' /geo/answers.json list: the fixed config/geo-answers.php
 * answers first and unchanged, the same shape as gs.construction's own
 * ConfigFaqCatalog — no per-service/per-area FAQ merge (jpeterson-design's
 * addition), since this site's product pages carry no FAQ sections of their
 * own — followed by the FAQ bank (kit 0.24.0, 2026-11-10): the answers
 * seo:faq-bank derived from this site's own pages, each carrying the page
 * it restates as `url` for the feed's Question node. FaqBankCatalog::merge()
 * keeps the hand-written list in front, skips a bank question that repeats
 * a hand-written one, and caps the whole list. A deploy that has not
 * migrated the bank's tables yet serves the hand-written list alone rather
 * than a 500. Bound in AppServiceProvider.
 */
final class ConfigFaqCatalog implements FaqCatalog
{
    public function __construct(private readonly FaqBankStore $store) {}

    public function items(): array
    {
        $handWritten = (array) config('geo-answers.answers', []);

        return $this->store->exists() ? FaqBankCatalog::merge($handWritten, $this->store) : $handWritten;
    }

    public function publisherExtras(): array
    {
        return [
            'knowsLanguage' => (array) config('geo-answers.meta.languages', ['English']),
        ];
    }
}
