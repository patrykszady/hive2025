<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use SsSystems\Platform\Kit;

/**
 * Capability probe. ss-systems' HttpSiteApiClient::capabilities() reads
 * data.domains to decide which admin screens to show for this site.
 *
 * Hive ships two: `dashboard-stats` (new companies/users, see
 * DashboardStatsController) and `seo` (SeoSnapshotController — a stub
 * today; the shared Site Pulse block arrives with platform-kit 0.10.0).
 * No `js-errors`: this app tracks no client-side JS error log to expose.
 * No `platforms`/`social-media`/etc: those screens don't apply here — see
 * PlatformsController's docblock for why `platforms/status` still exists
 * despite that (the SEO screen's Connect Services modal calls it
 * regardless of ping domains).
 *
 * `platform_kit` is null until ss-systems/platform-kit is installed here —
 * class_exists keeps this honest rather than erroring while the package is
 * absent (another build is releasing kit 0.10.0; this app does not
 * install it as part of this change).
 */
class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'site' => config('app.name', 'Hive Contractors'),
                'platform_kit' => class_exists(Kit::class) ? Kit::VERSION : null,
                'domains' => [
                    'dashboard-stats',
                    'seo',
                ],
                'brand' => [
                    'name' => config('app.name', 'Hive Contractors'),
                    'logo' => null,
                    'logo_dark' => null,
                    'accent' => null,
                ],
            ],
        ]);
    }
}
