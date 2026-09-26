<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET platforms/status — read by the SEO screen's Connect Services modal
 * (App\Livewire\Admin\SeoConnectServices on ss-systems), which calls this
 * endpoint unconditionally regardless of whether a site declares the
 * `platforms` ping domain (that only gates the modal's "Set up on
 * Platforms" link — see ss-systems/CLAUDE.md). Hive declares no
 * `platforms` domain (PingController) since it has no Business
 * Profile/Meta connections screen; this endpoint exists purely so the
 * modal renders calmly ("not connected") instead of failing to load at
 * all.
 *
 * `services` names which Connect Services sections apply here — Search
 * Console and Bing, neither actually wired up yet. `gsc.managed: 'server'`
 * matches the convention other sites use for a server-held service
 * account (no per-owner OAuth to drive from this screen).
 */
class PlatformsController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                'services' => ['gsc', 'bing'],
                'gsc' => [
                    'connected' => false,
                    'configured' => false,
                    'managed' => 'server',
                ],
                'bing' => [
                    'configured' => false,
                    'source' => null,
                ],
            ],
        ]);
    }
}
