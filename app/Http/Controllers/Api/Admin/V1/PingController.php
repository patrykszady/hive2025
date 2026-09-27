<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Support\AdminApi;
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
                // Declared by each routes/api-admin/*.php file (App\Support\AdminApi).
                'domains' => AdminApi::domains(),
                'brand' => [
                    'name' => config('app.name', 'Hive Contractors'),
                    // The hive mark the app draws inline (components/hive-logo),
                    // served as files so the central admin's sidebar can show
                    // it: indigo-900 strokes on light, indigo-300 on dark, the
                    // same two colours the app itself uses.
                    'logo' => asset('images/hive-mark.svg'),
                    'logo_dark' => asset('images/hive-mark-dark.svg'),
                    // The admin recolours itself from this ramp ([step => hex],
                    // Tailwind's indigo — the app's own --color-accent is
                    // indigo-500/600 and the mark is indigo-900), so
                    // hive.contractors/admin reads indigo like the app, not
                    // the stock sky every site without a ramp gets (2026-09-27).
                    'accent' => [
                        50 => '#eef2ff',
                        100 => '#e0e7ff',
                        200 => '#c7d2fe',
                        300 => '#a5b4fc',
                        400 => '#818cf8',
                        500 => '#6366f1',
                        600 => '#4f46e5',
                        700 => '#4338ca',
                        800 => '#3730a3',
                        900 => '#312e81',
                        950 => '#1e1b4b',
                    ],
                ],
            ],
        ]);
    }
}
