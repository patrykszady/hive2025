<?php

namespace App\Support\Seo\Reports;

use App\Support\SeoStorage;
use SsSystems\Platform\Reports\Contracts\ReportStorage;

/**
 * ReportStorage for this site — delegates to the existing
 * App\Support\SeoStorage::path() (the identity function here; kept exactly
 * as-is since SeoReportController and EloquentHealthDataReader still call
 * it directly and neither moves in this port). This adapter exists only so
 * SsSystems\Platform\Reports\Console\KitReportCommand::maybeSaveMarkdown()
 * has the one-method contract it needs without the kit ever hardcoding
 * this app's own SeoStorage class — gsc's tenant-scoped equivalent binds
 * the same interface to its own `tenants/{slug}/`-prefixing class instead.
 */
class ReportPathStorage implements ReportStorage
{
    public function path(string $relative): string
    {
        return SeoStorage::path($relative);
    }
}
