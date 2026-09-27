<?php

use SsSystems\Platform\Reports\ReportRegistry;

/**
 * Registry of scheduled SEO markdown reports. Keys are file names (without
 * extension) under storage/app/reports/; each entry names the artisan
 * command that regenerates the report. Read by App\Http\Controllers\Api\
 * Admin\V1\SeoReportController's index/show/regenerate actions. Ported
 * verbatim from dawnsellshomes' identical file — built from the shared
 * ss-systems/platform-kit's ReportRegistry (the single source of truth for
 * the ten reports' label/description/command and, via `requires`, which
 * capabilities each one needs) rather than hand-listing commands here.
 * Whether each one is actually AVAILABLE on this site is a runtime question
 * answered by App\Support\Seo\Reports\ReportCapabilities, not by this file
 * — see SeoReportController::fileEntry()/regenerate().
 */
return [

    'reports' => collect(ReportRegistry::all())
        ->map(fn (array $meta, string $key) => [
            'label' => $meta['label'],
            'description' => $meta['description'],
            'command' => $meta['command'],
            'requires' => $meta['requires'],
        ])
        ->all(),

];
