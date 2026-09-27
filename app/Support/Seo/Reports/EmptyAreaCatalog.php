<?php

namespace App\Support\Seo\Reports;

use SsSystems\Platform\Reports\Contracts\AreaCatalog;

/**
 * AreaCatalog for this site. Ported verbatim from dawnsellshomes'/
 * jpeterson-design's identical class — hive.contractors has no per-city or
 * per-area landing pages at all (it is a single marketing site for a SaaS
 * product, not a contractor with a service area), so there is nothing
 * honest to map to this contract's intro/local_intro/landmarks
 * `content_complete` check. Bound anyway (rather than left unbound) so the
 * container can still resolve HealthReport/AreaPagesAuditReport directly;
 * App\Support\Seo\Reports\ReportCapabilities deliberately does NOT list
 * `area_catalog` as provided, so AreaPagesAuditReport reads "not available
 * on this site" through the admin rather than silently running against an
 * empty catalog as if that were a real zero.
 */
class EmptyAreaCatalog implements AreaCatalog
{
    public function areas(): array
    {
        return [];
    }
}
