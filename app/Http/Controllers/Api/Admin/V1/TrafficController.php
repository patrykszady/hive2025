<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use SsSystems\Platform\Http\Admin\Concerns\BuildsTrafficSources;
use SsSystems\Platform\Traffic\TrafficDays;

/**
 * GET /api/admin/v1/traffic/sources — where visitors came from, per channel
 * and source, from every provider this app wires up (kit 0.17's
 * BuildsTrafficSources). The SEO screen's "Where visitors come from" card
 * reads it; traffic:collect fills it. No cache-key override: this app is
 * single-tenant, so the trait's default (unprefixed) key is already right.
 */
class TrafficController extends Controller
{
    use BuildsTrafficSources;

    protected function trafficDays(): TrafficDays
    {
        return app(TrafficDays::class);
    }
}
