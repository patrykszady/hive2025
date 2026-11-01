<?php

namespace App\Support\Seo;

use App\Support\SeoStorage;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Visibility\Contracts\ReportSink;

/**
 * Where the kit's SEO tools drop a markdown report: the same reports/
 * folder this app's own seo:*-report commands write to (see
 * App\Support\SeoStorage), so a kit report (seo:competitor-brand-track
 * --markdown) sits beside this site's own (2026-11-01).
 */
final class SeoReportSink implements ReportSink
{
    public function write(string $relativePath, string $markdown): void
    {
        Storage::disk('local')->put(SeoStorage::path($relativePath), $markdown);
    }
}
