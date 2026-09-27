<?php

namespace App\Support\Seo\Inspection;

use SsSystems\Platform\Seo\Inspection\Contracts\TrackedPaths;

/**
 * TrackedPaths for this site: there is no 404-tracking table here, so the
 * 'tracked' off-sitemap pool simply contributes nothing. Ported from
 * dawnsellshomes' identical class.
 */
class NoTrackedPaths implements TrackedPaths
{
    public function recent404Paths(): array
    {
        return [];
    }
}
