<?php

namespace App\Support;

/**
 * Where generated SEO artefacts (markdown reports) live on the local disk.
 * Ported from dawnsellshomes' identical class: gsc's version scopes the
 * path per-tenant because one gsc codebase serves many Site rows; this app
 * is a single site with no Site model, so this is the identity function.
 * Kept as its own class (rather than inlined) so SeoReportController/the
 * kit's ReportRun/the report adapters read identically to the other kit
 * sites, and so a future multi-tenant need has one obvious place to add
 * scoping.
 */
class SeoStorage
{
    public static function path(string $relative): string
    {
        return $relative;
    }
}
