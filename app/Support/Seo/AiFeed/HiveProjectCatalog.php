<?php

namespace App\Support\Seo\AiFeed;

use SsSystems\Platform\Seo\AiFeed\Contracts\ProjectCatalog;

/**
 * This site's `projects` for /ai-feed.json: empty on purpose. The
 * marketing site publishes no case studies or customer projects — the
 * closest thing, the blog, is `HiveContentCatalog`'s — and the kit's
 * envelope expects the key either way, so this answers `[]` rather than
 * dressing a page list up as projects.
 */
final class HiveProjectCatalog implements ProjectCatalog
{
    public function projects(): array
    {
        return [];
    }
}
